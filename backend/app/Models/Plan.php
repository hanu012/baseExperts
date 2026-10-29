<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A subscription plan (SPEC section 5.4).
 *
 * NOT DELETABLE THROUGH THE PANEL, but for a different reason than
 * categories/subcategories/zones. SPEC section 10 covers those three because
 * subscription_items.item_id carries no foreign key and a delete would orphan
 * rows. Plans are referenced by subscriptions.plan_id, which IS a real
 * foreign key with ON DELETE RESTRICT — the database already refuses. Offering
 * a delete action would therefore just surface a raw integrity-constraint
 * error to an admin.
 *
 * Deactivating is the removal path, exactly as for the other three, and the
 * effect is the same: existing subscriptions keep working, the plan stops
 * being offered.
 *
 * Price and duration are snapshotted onto each subscription at purchase, so
 * editing a plan never rewrites what past customers were charged.
 */
class Plan extends Model
{
    use HasFactory;
    use HasTranslations;
    use RecordsAuditLog;

    /** Stored per language (en / hi / gu); reads return the request locale, falling back to English. */
    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_paise',
        'duration_days',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_paise' => 'integer',
            'duration_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * One row, enforced by a unique index on plan_quotas.plan_id.
     */
    public function quota(): HasOne
    {
        return $this->hasOne(PlanQuota::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Subscriptions currently running on this plan.
     *
     * Counted straight off subscriptions rather than through the
     * PreventsDeletionWhileSubscribed trait: that trait reads
     * subscription_items by item_type, and plans never appear there.
     */
    public function activeSubscriptionCount(): int
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->count();
    }

    /**
     * Every subscription that has ever used this plan.
     *
     * This is the number ON DELETE RESTRICT actually blocks on — including
     * expired and soft-deleted rows, which still hold the foreign key. A plan
     * with no active subscriptions can still be undeletable, and deactivating
     * it still affects the renewal path for everyone in this count.
     */
    public function totalSubscriptionCount(): int
    {
        return $this->subscriptions()->withTrashed()->count();
    }

    /**
     * Every audit entry for a plan carries the complete commercial terms, not
     * just the fields that changed.
     *
     * WHY THIS ONE IS A SNAPSHOT: a subscription copies `price_paise` and
     * `duration_days` at purchase, so money is already reconstructable from
     * the subscription itself. **Quota is not copied anywhere.** Raise Gold's
     * max_zones from 5 to 8 and every existing Gold subscriber silently gains
     * quota; lower it and they are over their limit. Nothing in the
     * subscription records what they actually bought — so this log is the only
     * place that answers "what did this plan offer on the day they signed up".
     *
     * A pure diff would answer "max_zones went 5 to 8" but would not let you
     * reconstruct the rest of the package without replaying every entry from
     * creation.
     *
     * @return array<string, mixed>
     */
    protected function auditSnapshotAttributes(): array
    {
        $quota = $this->relationLoaded('quota') ? $this->quota : $this->quota()->first();

        return array_filter([
            'price_paise' => $this->price_paise,
            'duration_days' => $this->duration_days,
            'is_active' => $this->is_active,
            'max_categories' => $quota?->max_categories,
            'max_subcategories' => $quota?->max_subcategories,
            'max_zones' => $quota?->max_zones,
            'max_photos' => $quota?->max_photos,
            'max_videos' => $quota?->max_videos,
            'priority_rank' => $quota?->priority_rank,
        ], fn ($value) => $value !== null);
    }

    /**
     * Rupees for display and form input; paise is what is stored.
     */
    public function priceInRupees(): string
    {
        return number_format($this->price_paise / 100, 2, '.', '');
    }

    /**
     * Converts a rupee amount entered by an admin into integer paise.
     *
     * Parses the decimal as a string rather than multiplying a float.
     * `(int) (1.15 * 100)` is 114, not 115 — 1.15 has no exact IEEE-754
     * representation and the cast truncates rather than rounds. Sweeping
     * 0.01–200.00 finds 1145 of 20000 amounts that convert wrong this way,
     * each undercharging by a paisa. Splitting on the decimal point cannot
     * drift. See MoneyConversionTest.
     */
    public static function rupeesToPaise(int|float|string|null $rupees): int
    {
        if ($rupees === null || $rupees === '') {
            return 0;
        }

        $value = trim((string) $rupees);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction ?? '0');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $paise = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$paise : $paise;
    }
}
