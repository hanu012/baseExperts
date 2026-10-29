<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records changes to audit_logs (SPEC section 5.14).
 *
 * Uses Eloquent model events rather than a global interceptor: each model
 * opts in by using this trait, so what is audited is visible on the model
 * itself instead of registered somewhere far away.
 *
 * WHAT IS RECORDED: only the attributes that actually changed, in both
 * old_values and new_values. A model may widen new_values to a full snapshot
 * by overriding auditSnapshotAttributes() — Plan does, because subscriptions
 * snapshot price and duration at purchase but NOT quota, which makes this log
 * the only record of what an existing subscriber actually bought.
 *
 * WHAT IS NEVER RECORDED: see auditExcluded(). Secrets must be excluded by a
 * deny-list rather than by remembering.
 *
 * A CAVEAT THAT MATTERS HERE: saveQuietly() fires no events, so anything
 * written that way is invisible to this trait. User::deleteWithTombstone()
 * deliberately renames the email quietly, so it records its own audit entry
 * explicitly rather than relying on these hooks.
 */
trait RecordsAuditLog
{
    public static function bootRecordsAuditLog(): void
    {
        static::created(function (Model $model) {
            $keys = array_keys($model->auditableAttributes($model->getAttributes()));

            $model->writeAuditLog('created', [], $model->auditCastValues($keys));
        });

        static::updated(function (Model $model) {
            $keys = array_keys($model->auditableAttributes($model->getChanges()));

            // Nothing meaningful changed — a touch, or only excluded fields.
            if ($keys === []) {
                return;
            }

            $before = [];
            foreach ($keys as $key) {
                // getOriginal() applies casts; getChanges() does not.
                $before[$key] = $model->getOriginal($key);
            }

            $model->writeAuditLog('updated', $before, $model->auditNewValues($model->auditCastValues($keys)));
        });
    }

    /**
     * Attributes never written to the audit log, on any model.
     *
     * @return array<int, string>
     */
    protected function auditExcluded(): array
    {
        return [
            'password',
            'remember_token',
            'updated_at',
            'created_at',
        ];
    }

    /**
     * Extra attributes always included in new_values, beyond what changed.
     *
     * Empty by default: the log records a diff. Plan overrides it so every
     * entry is a point-in-time snapshot of the commercial terms.
     *
     * @return array<string, mixed>
     */
    protected function auditSnapshotAttributes(): array
    {
        return [];
    }

    /**
     * The model an entry is filed against.
     *
     * PlanQuota overrides this to file against its Plan, so a plan's history
     * is one timeline covering both price and quota rather than being split
     * across two entity types.
     */
    public function auditableTarget(): ?Model
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExcluded()));
    }

    /**
     * Reads the given keys through their casts.
     *
     * getChanges() and getAttributes() both return RAW values, so a
     * json/array-cast column would be logged as a JSON string — producing
     * JSON nested inside the log's own JSON, which is awkward to read and
     * breaks any consumer expecting the same shape the model exposes.
     * getAttribute() applies the cast, so an array stays an array and an enum
     * serialises to its backed value.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function auditCastValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->getAttribute($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed>
     */
    protected function auditNewValues(array $changed): array
    {
        return array_merge($this->auditSnapshotAttributes(), $changed);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function writeAuditLog(string $action, array $before, array $after): void
    {
        $target = $this->auditableTarget();

        if ($target === null || $target->getKey() === null) {
            return;
        }

        AuditLog::create([
            // Null for system changes: seeders, console commands, queued jobs.
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->getKey(),
            'old_values' => $before === [] ? null : $before,
            'new_values' => $after === [] ? null : $after,
            // Absent in console context, which is itself a useful signal.
            'ip_address' => Request::hasSession() || Request::server('REMOTE_ADDR') ? Request::ip() : null,
            'user_agent' => Request::header('User-Agent'),
        ]);
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }
}
