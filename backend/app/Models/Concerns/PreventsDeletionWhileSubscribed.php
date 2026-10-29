<?php

namespace App\Models\Concerns;

use App\Exceptions\RecordInUseException;
use Illuminate\Support\Facades\DB;

/**
 * Blocks hard deletion of master data that a subscription still points at.
 *
 * WHY THIS EXISTS: subscription_items records `item_type` + `item_id` in one
 * table covering categories, subcategories and zones, so `item_id` can carry
 * no foreign key. Without this guard, deleting a category silently orphans
 * every subscription row that bought it — the vendor keeps paying for a
 * service that no longer resolves, and the orphan is invisible until a
 * customer search quietly returns nothing.
 *
 * This is a second control, not the only one: the admin UI should also hide
 * or disable the delete action. Hiding a button is not enforcement — an
 * artisan command, a tinker session, or a future API route all bypass it.
 */
trait PreventsDeletionWhileSubscribed
{
    /**
     * The `item_type` value this model is stored under in subscription_items.
     */
    abstract public function subscriptionItemType(): string;

    /**
     * Human-readable name used in the error message.
     */
    protected function recordInUseLabel(): string
    {
        return class_basename($this).' "'.($this->name ?? $this->getKey()).'"';
    }

    /**
     * Extra references to count beyond this model's own rows. Category
     * overrides this to include its subcategories, which a database-level
     * cascade would otherwise delete without ever firing their own events.
     *
     * @return array<int, array{type: string, ids: array<int, int>}>
     */
    protected function additionalSubscriptionReferences(): array
    {
        return [];
    }

    public static function bootPreventsDeletionWhileSubscribed(): void
    {
        static::deleting(function ($model) {
            $count = $model->countSubscriptionReferences();

            if ($count > 0) {
                throw RecordInUseException::referencedBySubscriptions(
                    $model->recordInUseLabel(),
                    $count
                );
            }
        });
    }

    /**
     * How many subscriptions reference this — distinct subscriptions, not
     * subscription_items rows. One subscription stores a row for the
     * category plus one per subcategory under it, so counting rows showed
     * "9 subscriptions" for a category two vendors had bought.
     */
    public function countSubscriptionReferences(): int
    {
        $query = DB::table('subscription_items')
            ->where(function ($q) {
                $q->where('item_type', $this->subscriptionItemType())
                    ->where('item_id', $this->getKey());
            });

        foreach ($this->additionalSubscriptionReferences() as $reference) {
            if ($reference['ids'] === []) {
                continue;
            }

            $query->orWhere(function ($q) use ($reference) {
                $q->where('item_type', $reference['type'])
                    ->whereIn('item_id', $reference['ids']);
            });
        }

        return $query->distinct()->count('subscription_id');
    }

    /**
     * True when this record can be safely hard-deleted.
     */
    public function isDeletable(): bool
    {
        return $this->countSubscriptionReferences() === 0;
    }
}
