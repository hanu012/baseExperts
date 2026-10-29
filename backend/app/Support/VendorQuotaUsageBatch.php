<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * Quota used/max for MANY vendors at once — the compact usage figures the
 * salesman's vendor list draws its mini bars from.
 *
 * [ActiveSubscriptionSummary] answers the same question for one vendor and
 * is the right tool on a detail screen. Calling it per row would run a
 * handful of queries per vendor, so a salesman with thirty vendors would
 * pay for a hundred-plus round trips to render one list. This runs a fixed
 * three regardless of how many vendors are passed in: the subscriptions,
 * their items, and the media counts.
 *
 * Only the three sellable dimensions are counted here. Photos and videos
 * are vendor-supplied content rather than something a salesman sells, and
 * the detail screen already shows them — keeping them out avoids a fourth
 * query for numbers the list does not use.
 */
class VendorQuotaUsageBatch
{
    /** @var array<int, array<string, array<string, int>>> keyed by vendor id */
    private array $usageByVendor = [];

    /**
     * @param  Collection<int, Vendor>  $vendors
     */
    public function __construct(Collection $vendors)
    {
        $this->build($vendors);
    }

    /**
     * Usage for one vendor, or null when they have no active subscription —
     * the same "Not subscribed" state the list already renders.
     *
     * @return array<string, array<string, int>>|null
     */
    public function for(Vendor $vendor): ?array
    {
        return $this->usageByVendor[$vendor->id] ?? null;
    }

    /**
     * @param  Collection<int, Vendor>  $vendors
     */
    private function build(Collection $vendors): void
    {
        // currentActiveSubscription() is the single definition of "active or
        // still in grace"; resolving it per vendor here is a lookup over the
        // already-eager-loaded subscriptions relation, not a query.
        $subscriptionsByVendor = [];

        foreach ($vendors as $vendor) {
            $subscription = $vendor->currentActiveSubscription();

            if ($subscription !== null && $subscription->plan?->quota !== null) {
                $subscriptionsByVendor[$vendor->id] = $subscription;
            }
        }

        if ($subscriptionsByVendor === []) {
            return;
        }

        $itemCounts = $this->itemCountsFor(array_map(
            fn (Subscription $subscription) => $subscription->id,
            $subscriptionsByVendor,
        ));

        foreach ($subscriptionsByVendor as $vendorId => $subscription) {
            $counts = $itemCounts[$subscription->id] ?? [];

            $this->usageByVendor[$vendorId] = [
                'categories' => [
                    'used' => $counts['category'] ?? 0,
                    // effectiveQuota() folds in purchased add-ons on top of
                    // the bare plan limit, so a vendor who bought extra
                    // never renders as over 100%.
                    'max' => $subscription->effectiveQuota('categories'),
                ],
                'subcategories' => [
                    'used' => $counts['subcategory'] ?? 0,
                    'max' => $subscription->effectiveQuota('subcategories'),
                ],
                'zones' => [
                    'used' => $counts['zone'] ?? 0,
                    'max' => $subscription->effectiveQuota('zones'),
                ],
            ];
        }
    }

    /**
     * @param  array<int, int>  $subscriptionIds
     * @return array<int, array<string, int>>  [subscriptionId => [itemType => count]]
     */
    private function itemCountsFor(array $subscriptionIds): array
    {
        return SubscriptionItem::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->selectRaw('subscription_id, item_type, count(*) as total')
            ->groupBy('subscription_id', 'item_type')
            ->get()
            ->groupBy('subscription_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'item_type')
                ->map(fn ($total) => (int) $total)
                ->all())
            ->all();
    }

    /**
     * Photo/video counts for a set of vendors, in one query. Not used by the
     * list, but kept beside the rest so a caller that needs all five
     * dimensions batched has somewhere obvious to reach for.
     *
     * @param  Collection<int, Vendor>  $vendors
     * @return array<int, array<string, int>>  [vendorId => [type => count]]
     */
    public static function mediaCountsFor(Collection $vendors): array
    {
        if ($vendors->isEmpty()) {
            return [];
        }

        return Media::query()
            ->where('mediable_type', $vendors->first()->getMorphClass())
            ->whereIn('mediable_id', $vendors->pluck('id'))
            ->where('moderation_status', '!=', 'rejected')
            ->selectRaw('mediable_id, type, count(*) as total')
            ->groupBy('mediable_id', 'type')
            ->get()
            ->groupBy('mediable_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'type')
                ->map(fn ($total) => (int) $total)
                ->all())
            ->all();
    }
}
