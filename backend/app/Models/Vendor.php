<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use App\Models\Concerns\TracksFileDisk;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor profile, 1:1 with a user carrying role = vendor.
 *
 * Lifecycle (SPEC section 7):
 *   draft -> pending_payment -> pending_verification (self-registered only)
 *         -> active -> grace -> expired
 *
 * An admin creating a vendor here starts it at `draft`: there is no
 * subscription yet, which is the same position the salesman flow is in before
 * payment (SPEC section 2.2). `pending_verification` is explicitly for
 * self-registered vendors only.
 *
 * `is_suspended` is a separate admin flag for policy violations, independent
 * of subscription dates.
 */
class Vendor extends Model
{
    use HasFactory;
    use RecordsAuditLog;
    use SoftDeletes;
    use TracksFileDisk;

    protected $fillable = [
        'user_id',
        'business_name',
        'owner_name',
        'phone',
        'address',
        'city',
        'about',
        'latitude',
        'longitude',
        'status',
        'is_suspended',
        'shop_photo_path',
        'id_proof_type',
        'id_proof_path',
        'disk',
        'created_by_salesman_id',
        'verified_at',
        'verified_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            // float, not decimal:7 — Laravel's decimal cast returns a STRING to
            // preserve precision, so the API emitted "23.0300000" and the apps
            // crashed casting it to num. A double holds 10 significant digits
            // exactly, which is all a lat/lng of this scale needs.
            'latitude' => 'float',
            'longitude' => 'float',
            'is_suspended' => 'boolean',
            'verified_at' => 'datetime',
            // Likewise a number rather than "0.00" — see latitude above.
            'rating_avg' => 'float',
            'rating_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A vendor carries TWO files - shop photo and ID proof - against ONE
     * `disk` column, unlike Category which has a single icon.
     *
     * One column is honest here because both KYC files are written by the
     * same upload request to the same disk, and the R2 migration should move
     * a row's files together anyway: a vendor whose shop photo is on R2 while
     * its ID proof is still local is a state nobody benefits from.
     *
     * The trait's single-column contract is satisfied by naming the primary
     * file, and the stamping override below covers either path being set.
     */
    public function fileDiskPathColumn(): string
    {
        return 'shop_photo_path';
    }

    /**
     * Both KYC files, so the disk is stamped whichever one is uploaded.
     *
     * This declaration is the whole of it — TracksFileDisk checks every
     * column listed here. There is deliberately no bespoke boot hook on this
     * model: an earlier version had one, which is fragile twice over. It has
     * to be hand-written for each multi-file model (Phase 4's vendor
     * self-service KYC would need its own copy), and naming it wrongly fails
     * silently, since Eloquent only auto-calls `boot{TraitName}` and
     * `booted()`.
     *
     * @return array<int, string>
     */
    public function fileDiskPathColumns(): array
    {
        return ['shop_photo_path', 'id_proof_path'];
    }

    /**
     * KYC documents, one per DocumentType (VendorKycService). Separate
     * from shop_photo_path, which is the public profile picture customers
     * see; id_proof_path/id_proof_type are the pre-2026-10-28 single ID
     * proof, copied into here by DocumentTypeSeeder and no longer written.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function createdBySalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'created_by_salesman_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Rows, not customers — `favoritedBy()` would be misleading if it
     * returned Favorite records, so the name says exactly what it holds.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Portfolio uploads (SPEC section 3 item 5, task 4.5) — photos/videos
     * of completed work, quota-capped by the active subscription's plan.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * The subscription the vendor dashboard, services-management
     * screens, and the public vendor detail page all key off (task
     * 4.2/4.4, widened in task 7.1). Not a relation: it needs a
     * `where`/`latest` beyond what a plain `hasOne` expresses, same
     * reasoning `scopeActive()` needing the subscription-currency
     * check documents.
     *
     * WIDENED beyond a flat "active and not past end_date" (task
     * 7.1): SPEC section 7 treats Grace as a still-visible renewal
     * window, so a `grace` subscription still counts here as long as
     * it's within `grace_period_days` of its `end_date` — see
     * VendorSearchService's class docblock for the full reasoning,
     * since this method is meant to stay in lockstep with that query
     * (VendorDetailController's own comment says as much).
     */
    public function currentActiveSubscription(): ?Subscription
    {
        $today = now()->startOfDay();
        $gracePeriodDays = (int) Setting::get('grace_period_days', 7);
        $graceCutoff = $today->copy()->subDays($gracePeriodDays);

        return $this->subscriptions()
            ->where(function ($query) use ($today, $graceCutoff) {
                $query->where(function ($active) use ($today) {
                    $active->where('status', 'active')->where('end_date', '>=', $today);
                })->orWhere(function ($grace) use ($graceCutoff) {
                    $grace->where('status', 'grace')->where('end_date', '>=', $graceCutoff);
                });
            })
            ->with('plan.quota')
            ->latest('end_date')
            ->first();
    }

    /**
     * Visible to customers only when not suspended and either active
     * or in grace — and, per SPEC section 4.4, only alongside an
     * unexpired-or-in-grace subscription, which the matching query
     * (or currentActiveSubscription()) adds. Widened from
     * active-only in task 7.1 — see VendorSearchService's class
     * docblock.
     *
     * Also requires a live user: deleting an account soft-deletes the
     * user but leaves the vendor row, which must not stay listed.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'grace'])
            ->where('is_suspended', false)
            ->whereHas('user');
    }

    /**
     * The admin verification queue (SPEC section 5.8) — self-registered
     * vendors awaiting Approve/Reject.
     */
    public function scopePendingVerification($query)
    {
        return $query->where('status', 'pending_verification');
    }
}
