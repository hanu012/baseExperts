<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Vendor;
use App\Models\Zone;
use App\Support\GeoDistance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * SPEC section 4 item 4's match query: `subcategory = X AND zone contains
 * customer_location AND vendor.status = active AND subscription.end_date
 * >= today`, sorted per section 4 item 5.
 *
 * WIDENED beyond that literal wording (task 7.1): SPEC section 7 and
 * BUILD_PLAN 7.1/8.2 both make clear that Grace is a still-visible
 * renewal window — only Expired actually drops a vendor from search.
 * A flat `end_date >= today` can never be true once a subscription is
 * genuinely in `grace` (its end_date is, by definition, already in
 * the past), so the bound below is conditional on status instead:
 * `active` rows still need `end_date >= today` (a same-day safety net
 * for the gap before the nightly expiry job runs), `grace` rows are
 * bounded by `end_date >= today - grace_period_days` instead.
 * `Vendor::currentActiveSubscription()` applies the identical bound —
 * see that method's docblock — since it backs the vendor detail page,
 * which is meant to stay in lockstep with this query.
 *
 * Zone resolution is delegated to ZoneMatcher (task 4.6) — this class only
 * adds the subcategory/zone coverage join against subscription_items and
 * the sort.
 */
class VendorSearchService
{
    /**
     * SPEC leaves the exact review-count floor unspecified beyond "a
     * minimum threshold so one 5-star doesn't outrank a 4.6 with 80
     * reviews" — 5 is a placeholder default until there's a real product
     * answer. Live since task 5.5: `RecalculatesVendorRating` keeps
     * `vendors.rating_avg`/`rating_count` current on every review
     * write/hide/unhide, so this constant actively gates the sort now.
     */
    public const MIN_REVIEWS_FOR_RATING_SORT = 5;

    public function __construct(private readonly ZoneMatcher $zoneMatcher)
    {
    }

    /**
     * SPEC section 4 item 5's sort (plan priority, then the rating floor,
     * then recency) is the default and is always applied as the PRIMARY
     * key — a vendor's paid placement does not get reshuffled by a
     * customer's sort choice. These three chips (task 5.3's redesign)
     * only change the SECONDARY key within that ordering, matching how
     * marketplaces generally keep promoted listings on top regardless of
     * how the customer chooses to sort the rest.
     */
    public const SORT_NEAREST = 'nearest';

    public const SORT_RATING = 'rating';

    public const SORT_NEW = 'new';

    /**
     * @return array{zone: ?Zone, paginator: ?LengthAwarePaginator}
     */
    public function search(
        int $subcategoryId,
        ?float $lat,
        ?float $lng,
        ?string $pincode,
        int $perPage = 15,
        // Defaults to the rating/recency secondary sort, matching this
        // method's own behaviour before the sort chips existed — every
        // existing caller that does not pass $sort (the home screen's
        // "Vendors near you" rail, any test omitting it) keeps the exact
        // ordering it always had. "Nearest" is opt-in, not the new
        // default, even though it IS the default chip selected on the
        // search-results screen — that screen passes it explicitly.
        string $sort = self::SORT_RATING,
    ): array {
        $zone = null;

        if ($lat !== null && $lng !== null) {
            $zone = $this->zoneMatcher->matchPoint($lat, $lng);
        }

        if ($zone === null && $pincode !== null) {
            $zone = $this->zoneMatcher->matchPincode($pincode);
        }

        if ($zone === null) {
            // Not an error — mirrors CustomerController::updateLocation()'s
            // same "no match" outcome.
            return ['zone' => null, 'paginator' => null];
        }

        $today = Carbon::today();
        $gracePeriodDays = (int) Setting::get('grace_period_days', 7);
        $graceCutoff = $today->copy()->subDays($gracePeriodDays);

        // A vendor has at most one active+unexpired subscription at a
        // time (renewal creates a fresh row; the old one is superseded,
        // never left running alongside it — see Subscription's own
        // docblock), so this join can't multiply rows per vendor and
        // needs no distinct()/groupBy().
        $query = Vendor::query()
            // Vendor::scopeActive() leaves 'status'/'is_suspended'
            // unqualified, which becomes ambiguous once subscriptions
            // (its own 'status' column) is joined in — qualified here
            // instead. Widened to include 'grace' — see class docblock.
            ->whereIn('vendors.status', ['active', 'grace'])
            ->where('vendors.is_suspended', false)
            ->whereHas('user')
            ->join('subscriptions', 'subscriptions.vendor_id', '=', 'vendors.id')
            ->join('plan_quotas', 'plan_quotas.plan_id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', ['active', 'grace'])
            ->where(function ($query) use ($today, $graceCutoff) {
                $query->where(function ($active) use ($today) {
                    $active->where('subscriptions.status', 'active')
                        ->where('subscriptions.end_date', '>=', $today);
                })->orWhere(function ($grace) use ($graceCutoff) {
                    $grace->where('subscriptions.status', 'grace')
                        ->where('subscriptions.end_date', '>=', $graceCutoff);
                });
            })
            // A raw join bypasses Subscription's own SoftDeletes global
            // scope, so it's re-applied explicitly here.
            ->whereNull('subscriptions.deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw(1)
                ->from('subscription_items')
                ->whereColumn('subscription_items.subscription_id', 'subscriptions.id')
                ->where('subscription_items.item_type', 'subcategory')
                ->where('subscription_items.item_id', $subcategoryId))
            // A deactivated subcategory (or one under a deactivated
            // category) is gone from every browse surface, so a direct
            // search for it comes back empty too.
            ->whereExists(fn ($query) => $query->selectRaw(1)
                ->from('subcategories')
                ->join('categories', 'categories.id', '=', 'subcategories.category_id')
                ->where('subcategories.id', $subcategoryId)
                ->where('subcategories.is_active', true)
                ->where('categories.is_active', true))
            ->whereExists(fn ($query) => $query->selectRaw(1)
                ->from('subscription_items')
                ->whereColumn('subscription_items.subscription_id', 'subscriptions.id')
                ->where('subscription_items.item_type', 'zone')
                ->where('subscription_items.item_id', $zone->id))
            ->select('vendors.*');

        // Distance is selected whenever a point is available, independent
        // of the chosen sort — "Top rated" still shows "1.2 km" on each
        // card, it just is not what the list is ordered by.
        if ($lat !== null && $lng !== null) {
            [$expression, $bindings] = GeoDistance::sqlExpression('vendors.latitude', 'vendors.longitude', $lat, $lng);
            $query->selectRaw("{$expression} as distance_km", $bindings);
        }

        // Higher rank = higher tier = listed first (Silver 1, Gold 2,
        // Platinum 3 in PlanSeeder; the admin help text says the same).
        $query->orderByDesc('plan_quotas.priority_rank');
        $this->applySecondarySort($query, $sort, $lat, $lng);

        return ['zone' => $zone, 'paginator' => $query->paginate($perPage)];
    }

    /**
     * The three list-screen sort chips. "Nearest" silently falls back to
     * the rating secondary sort when no point was given (a pincode-only
     * lookup has no coordinate to measure from) — the chip still shows,
     * it is just a no-op rather than an error the customer cannot act on.
     */
    private function applySecondarySort(Builder $query, string $sort, ?float $lat, ?float $lng): void
    {
        if ($sort === self::SORT_NEW) {
            $query->orderByDesc('vendors.created_at');

            return;
        }

        if ($sort === self::SORT_NEAREST && $lat !== null && $lng !== null) {
            // No stored coordinates means a NULL distance, which MySQL and
            // MariaDB sort first ascending — those vendors go last instead.
            $query->orderByRaw('(vendors.latitude IS NULL OR vendors.longitude IS NULL)')
                ->orderBy('distance_km');

            return;
        }

        // SORT_RATING, and SORT_NEAREST's no-coordinate fallback.
        $query->orderByRaw(
            'CASE WHEN vendors.rating_count >= ? THEN vendors.rating_avg ELSE 0 END DESC',
            [self::MIN_REVIEWS_FOR_RATING_SORT]
        )->orderByDesc('vendors.created_at');
    }

    /**
     * How many matching vendors cover each subcategory in this zone —
     * the customer subcategories screen's "X vendors" line (task 5.1's
     * redesign). Same eligibility rule as search() above (status, active
     * subscription, zone coverage); deliberately not calling search()
     * itself, which paginates and sorts a single subcategory — this
     * counts several subcategories in one query instead of N round trips.
     *
     * @param  array<int, int>  $subcategoryIds
     * @return array<int, int> subcategory_id => vendor count
     */
    public function countBySubcategory(array $subcategoryIds, Zone $zone): array
    {
        if ($subcategoryIds === []) {
            return [];
        }

        $today = Carbon::today();
        $gracePeriodDays = (int) Setting::get('grace_period_days', 7);
        $graceCutoff = $today->copy()->subDays($gracePeriodDays);

        $rows = Vendor::query()
            ->whereIn('vendors.status', ['active', 'grace'])
            ->where('vendors.is_suspended', false)
            ->whereHas('user')
            ->join('subscriptions', 'subscriptions.vendor_id', '=', 'vendors.id')
            ->join('subscription_items as sub_si', function ($join) use ($subcategoryIds) {
                $join->on('sub_si.subscription_id', '=', 'subscriptions.id')
                    ->where('sub_si.item_type', 'subcategory')
                    ->whereIn('sub_si.item_id', $subcategoryIds);
            })
            ->whereIn('subscriptions.status', ['active', 'grace'])
            ->where(function ($query) use ($today, $graceCutoff) {
                $query->where(function ($active) use ($today) {
                    $active->where('subscriptions.status', 'active')
                        ->where('subscriptions.end_date', '>=', $today);
                })->orWhere(function ($grace) use ($graceCutoff) {
                    $grace->where('subscriptions.status', 'grace')
                        ->where('subscriptions.end_date', '>=', $graceCutoff);
                });
            })
            ->whereNull('subscriptions.deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw(1)
                ->from('subscription_items as zone_si')
                ->whereColumn('zone_si.subscription_id', 'subscriptions.id')
                ->where('zone_si.item_type', 'zone')
                ->where('zone_si.item_id', $zone->id))
            ->selectRaw('sub_si.item_id as subcategory_id, count(distinct vendors.id) as vendor_count')
            ->groupBy('sub_si.item_id')
            ->get();

        return $rows->pluck('vendor_count', 'subcategory_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
