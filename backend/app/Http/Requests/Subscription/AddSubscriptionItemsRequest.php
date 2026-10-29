<?php

namespace App\Http\Requests\Subscription;

use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Support\ServiceSelectionValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Adding categories/subcategories/zones to the caller's OWN active
 * subscription, within whatever quota is still unused (SPEC section 3.3,
 * task 4.4) — distinct from StoreSubscriptionRequest, which only ever
 * creates a brand-new subscription for a `draft` vendor.
 *
 * `vendor_id`/`plan_id` are never accepted here: everything resolves from
 * `$request->user()->vendor->currentActiveSubscription()`, the same
 * "never trust a client-supplied id" shape /vendors/me and /salesmen/me/*
 * already use.
 *
 * TWO MODES:
 *
 * - Additive (default): only ids NOT already on the subscription reach the
 *   service layer, and nothing is ever removed — an omitted existing id is
 *   simply left alone.
 *
 * - Replace (`replace: true`, what the vendor app sends): the submission is
 *   the vendor's complete new selection. Ids missing from it are removed,
 *   so a vendor can untick a category, subcategory or zone they picked
 *   earlier and use that room for something else. Quota is then checked
 *   against the final selection as a whole, not used + new.
 *
 * Either way, an already-selected id that is kept is not re-validated —
 * one that later went inactive stays rather than blocking the save.
 */
class AddSubscriptionItemsRequest extends FormRequest
{
    private ?Subscription $subscription = null;

    /** @var array<int, int> */
    private array $newCategoryIds = [];

    /** @var array<int, int> */
    private array $newSubcategoryIds = [];

    /** @var array<int, int> */
    private array $newZoneIds = [];

    /** @var array<int, int> */
    private array $removedCategoryIds = [];

    /** @var array<int, int> */
    private array $removedSubcategoryIds = [];

    /** @var array<int, int> */
    private array $removedZoneIds = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer'],

            'subcategory_ids' => ['sometimes', 'array'],
            'subcategory_ids.*' => ['integer'],

            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer'],

            'replace' => ['sometimes', 'boolean'],
        ];
    }

    public function isReplace(): bool
    {
        return $this->boolean('replace');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vendor = $this->user()->vendor;

            if ($vendor === null) {
                $validator->errors()->add('subscription', 'No vendor profile exists for this account.');

                return;
            }

            $subscription = $vendor->currentActiveSubscription();

            if ($subscription === null || $subscription->plan->quota === null) {
                $validator->errors()->add(
                    'subscription',
                    'You do not have an active subscription to add services to.'
                );

                return;
            }

            $this->subscription = $subscription;

            $existing = $this->existingIdsByType($subscription);

            $submittedCategoryIds = array_unique(array_map('intval', (array) $this->input('category_ids', [])));
            $submittedSubcategoryIds = array_unique(array_map('intval', (array) $this->input('subcategory_ids', [])));
            $submittedZoneIds = array_unique(array_map('intval', (array) $this->input('zone_ids', [])));

            // Only genuinely new ids ever reach validation or the service —
            // an already-selected id (even one that later became inactive)
            // is simply dropped from consideration here, never re-checked.
            $this->newCategoryIds = array_values(array_diff($submittedCategoryIds, $existing['category']));
            $this->newSubcategoryIds = array_values(array_diff($submittedSubcategoryIds, $existing['subcategory']));
            $this->newZoneIds = array_values(array_diff($submittedZoneIds, $existing['zone']));

            if ($this->isReplace()) {
                $this->validateReplacement(
                    $validator,
                    $subscription,
                    $existing,
                    $submittedCategoryIds,
                    $submittedSubcategoryIds,
                    $submittedZoneIds,
                );

                return;
            }

            ServiceSelectionValidator::validate(
                $validator,
                $this->newCategoryIds,
                $this->newSubcategoryIds,
                $this->newZoneIds,
                // A newly added subcategory may belong to a category the
                // vendor already selected in an earlier request, not one
                // being added just now.
                allowedCategoryIds: array_values(array_unique(
                    array_merge($existing['category'], $this->newCategoryIds)
                )),
            );

            // effectiveQuota() (task 4.7) folds in any purchased add-on
            // quantity on top of the bare plan limit — a vendor who
            // bought "+2 categories" must be honored here too, not just
            // by the endpoint that sold them the extra room.
            $this->validateRemainingQuota(
                $validator,
                'category_ids',
                count($existing['category']),
                count($this->newCategoryIds),
                $subscription->effectiveQuota('categories'),
                'categories',
            );

            $this->validateRemainingQuota(
                $validator,
                'subcategory_ids',
                count($existing['subcategory']),
                count($this->newSubcategoryIds),
                $subscription->effectiveQuota('subcategories'),
                'subcategories',
            );

            $this->validateRemainingQuota(
                $validator,
                'zone_ids',
                count($existing['zone']),
                count($this->newZoneIds),
                $subscription->effectiveQuota('zones'),
                'zones',
            );
        });
    }

    /**
     * Replace mode: the submission is the whole new selection.
     *
     * @param  array{category: array<int,int>, subcategory: array<int,int>, zone: array<int,int>}  $existing
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $subcategoryIds
     * @param  array<int, int>  $zoneIds
     */
    private function validateReplacement(
        Validator $validator,
        Subscription $subscription,
        array $existing,
        array $categoryIds,
        array $subcategoryIds,
        array $zoneIds,
    ): void {
        $this->removedCategoryIds = array_values(array_diff($existing['category'], $categoryIds));
        $this->removedSubcategoryIds = array_values(array_diff($existing['subcategory'], $subcategoryIds));
        $this->removedZoneIds = array_values(array_diff($existing['zone'], $zoneIds));

        // A vendor with no category, service or area left is invisible to
        // every customer search — almost certainly not what they meant.
        foreach (['category_ids' => $categoryIds, 'subcategory_ids' => $subcategoryIds, 'zone_ids' => $zoneIds] as $field => $ids) {
            if ($ids === []) {
                $validator->errors()->add($field, 'Keep at least one selected.');
            }
        }

        // New ids get the full structural check; their parent category may
        // be any category in the final selection.
        ServiceSelectionValidator::validate(
            $validator,
            $this->newCategoryIds,
            $this->newSubcategoryIds,
            $this->newZoneIds,
            allowedCategoryIds: $categoryIds,
        );

        // A KEPT subcategory whose category was just removed would be left
        // orphaned — the app removes them together, but this is re-checked
        // rather than trusted.
        $keptSubcategoryIds = array_values(array_intersect($existing['subcategory'], $subcategoryIds));

        $orphaned = Subcategory::query()
            ->whereIn('id', $keptSubcategoryIds)
            ->whereNotIn('category_id', $categoryIds)
            ->exists();

        if ($orphaned) {
            $validator->errors()->add(
                'subcategory_ids',
                'A selected subcategory belongs to a category that is no longer selected.'
            );
        }

        // The final selection as a whole must fit the plan, add-ons included.
        foreach ([
            ['category_ids', $categoryIds, 'categories'],
            ['subcategory_ids', $subcategoryIds, 'subcategories'],
            ['zone_ids', $zoneIds, 'zones'],
        ] as [$field, $ids, $resource]) {
            $max = $subscription->effectiveQuota($resource);

            if (count($ids) > $max) {
                $validator->errors()->add(
                    $field,
                    "This plan allows at most {$max} {$resource}, but ".count($ids).' were selected.'
                );
            }
        }
    }

    private function validateRemainingQuota(
        Validator $validator,
        string $field,
        int $usedCount,
        int $newCount,
        int $max,
        string $label,
    ): void {
        if ($usedCount + $newCount > $max) {
            $remaining = max(0, $max - $usedCount);

            $validator->errors()->add(
                $field,
                "This plan allows at most {$max} {$label} — {$remaining} remaining, "
                ."but {$newCount} new ".($newCount === 1 ? 'was' : 'were')." selected."
            );
        }
    }

    /**
     * @return array{category: array<int,int>, subcategory: array<int,int>, zone: array<int,int>}
     */
    private function existingIdsByType(Subscription $subscription): array
    {
        $rows = SubscriptionItem::query()
            ->where('subscription_id', $subscription->id)
            ->get(['item_type', 'item_id']);

        return [
            'category' => $rows->where('item_type', 'category')->pluck('item_id')->all(),
            'subcategory' => $rows->where('item_type', 'subcategory')->pluck('item_id')->all(),
            'zone' => $rows->where('item_type', 'zone')->pluck('item_id')->all(),
        ];
    }

    /**
     * Populated during validation — only meaningful after the request has
     * passed. The controller resolves the subscription here rather than
     * re-querying, so it acts on the exact row validation already checked.
     */
    public function subscription(): Subscription
    {
        return $this->subscription;
    }

    /**
     * @return array<int, int>
     */
    public function newCategoryIds(): array
    {
        return $this->newCategoryIds;
    }

    /**
     * @return array<int, int>
     */
    public function newSubcategoryIds(): array
    {
        return $this->newSubcategoryIds;
    }

    /**
     * @return array<int, int>
     */
    public function newZoneIds(): array
    {
        return $this->newZoneIds;
    }

    /**
     * Always empty in additive mode.
     *
     * @return array<int, int>
     */
    public function removedCategoryIds(): array
    {
        return $this->removedCategoryIds;
    }

    /**
     * @return array<int, int>
     */
    public function removedSubcategoryIds(): array
    {
        return $this->removedSubcategoryIds;
    }

    /**
     * @return array<int, int>
     */
    public function removedZoneIds(): array
    {
        return $this->removedZoneIds;
    }
}
