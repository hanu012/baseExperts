<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Media;
use App\Models\Subcategory;
use App\Models\SubscriptionItem;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Support\Collection;

/**
 * "What is this vendor currently subscribed to" — plan, expiry, quota
 * used/max per resource, and the names of everything they picked.
 *
 * Extracted from VendorController, which owned it privately while
 * `GET /vendors/me` was the only caller. It now has three: the vendor's own
 * dashboard, the salesman's vendor detail screen (a salesman needs to see
 * what they sold), and the admin panel's Vendors resource. Duplicating the
 * quota arithmetic per caller is how three screens end up disagreeing about
 * the same vendor's numbers, so it lives here instead.
 *
 * Read-only and side-effect free — safe to call from a controller, a
 * Filament infolist, or anywhere else.
 */
class ActiveSubscriptionSummary
{
    /**
     * @return array<string, mixed>|null  null when there is no active (or
     *                                    in-grace) subscription to describe.
     */
    public static function for(Vendor $vendor): ?array
    {
        $subscription = $vendor->currentActiveSubscription();

        if ($subscription === null || $subscription->plan->quota === null) {
            return null;
        }

        $itemIdsByType = SubscriptionItem::query()
            ->where('subscription_id', $subscription->id)
            ->get(['item_type', 'item_id'])
            ->groupBy('item_type')
            ->map(fn (Collection $rows) => $rows->pluck('item_id')->all());

        $mediaCounts = self::mediaCountsByType($vendor);

        return [
            'plan_name' => $subscription->plan->name,
            'end_date' => $subscription->end_date->toDateString(),
            'days_remaining' => now()->startOfDay()->diffInDays($subscription->end_date, absolute: false),
            // effectiveQuota() (task 4.7) folds in any purchased add-on
            // quantity on top of the bare plan limit — otherwise this
            // summary would show a "used" count over its displayed
            // "max" the moment an add-on lets a vendor exceed the bare
            // plan limit.
            'quota' => [
                'categories' => [
                    'used' => count($itemIdsByType['category'] ?? []),
                    'max' => $subscription->effectiveQuota('categories'),
                ],
                'subcategories' => [
                    'used' => count($itemIdsByType['subcategory'] ?? []),
                    'max' => $subscription->effectiveQuota('subcategories'),
                ],
                'zones' => [
                    'used' => count($itemIdsByType['zone'] ?? []),
                    'max' => $subscription->effectiveQuota('zones'),
                ],
                // task 4.5: pending + approved count as used, only a
                // rejected upload frees its slot back up (see
                // StorePortfolioMediaRequest).
                'photos' => [
                    'used' => $mediaCounts['image'] ?? 0,
                    'max' => $subscription->effectiveQuota('photos'),
                ],
                'videos' => [
                    'used' => $mediaCounts['video'] ?? 0,
                    'max' => $subscription->effectiveQuota('videos'),
                ],
            ],
            // Names, not just counts (task 4.4's Services tab needs to show
            // what's actually selected) — deliberately NOT filtered to
            // currently-active rows: deactivating a category/zone doesn't
            // retroactively drop a vendor's existing selection, same
            // reasoning CategoryResource documents for its own no-delete
            // stance.
            'items' => [
                'categories' => self::namedItems(Category::class, $itemIdsByType['category'] ?? []),
                // Subcategories also carry their parent id: the vendor
                // app's Services screen lists them grouped under the
                // category they belong to, and without this the app would
                // have to fetch the whole category tree just to work out
                // which heading each name belongs under.
                'subcategories' => self::namedItems(
                    Subcategory::class,
                    $itemIdsByType['subcategory'] ?? [],
                    ['category_id'],
                ),
                'zones' => self::namedItems(Zone::class, $itemIdsByType['zone'] ?? []),
            ],
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $extraColumns  Additional columns to select
     *                                            and pass through verbatim.
     * @return array<int, array<string, mixed>>
     */
    private static function namedItems(
        string $modelClass,
        array $ids,
        array $extraColumns = [],
    ): array {
        if ($ids === []) {
            return [];
        }

        return $modelClass::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(array_merge(['id', 'name'], $extraColumns))
            ->map(function ($model) use ($extraColumns) {
                $item = ['id' => $model->id, 'name' => $model->name];

                foreach ($extraColumns as $column) {
                    $item[$column] = $model->{$column};
                }

                return $item;
            })
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private static function mediaCountsByType(Vendor $vendor): array
    {
        return Media::query()
            ->where('mediable_type', $vendor->getMorphClass())
            ->where('mediable_id', $vendor->id)
            ->where('moderation_status', '!=', 'rejected')
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();
    }
}
