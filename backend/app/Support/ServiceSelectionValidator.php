<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Zone;
use Illuminate\Validation\Validator;

/**
 * The structural rules a category/subcategory/zone selection must satisfy,
 * shared by every place a vendor picks services (SPEC sections 6 and 3.3):
 * `StoreSubscriptionRequest` (initial subscribe) and
 * `AddSubscriptionItemsRequest` (adding within remaining quota, task 4.4).
 *
 * Deliberately does NOT include any quota-count check — that differs by
 * caller (absolute plan max at subscribe time vs. remaining quota when
 * adding later) and stays local to each Form Request. This class is only
 * the part proven identical between the two: a category must be active, a
 * subcategory must be active and belong to a category also being selected,
 * and a zone must be active and a leaf (SPEC section 8 — a zone with
 * children is navigation only, never itself a valid selection).
 *
 * Extracted rather than left duplicated so the two callers cannot silently
 * drift apart on what counts as a valid selection — see
 * ServiceSelectionValidatorTest for the drift-detection case covering both
 * endpoints at once.
 */
final class ServiceSelectionValidator
{
    /**
     * @param  array<int, int>  $categoryIds  Ids to validate as active/existing.
     * @param  array<int, int>  $subcategoryIds  Ids to validate as active/existing.
     * @param  array<int, int>  $zoneIds  Ids to validate as active/existing/leaf.
     * @param  array<int, int>|null  $allowedCategoryIds  The full set a
     *   subcategory's parent may belong to — defaults to $categoryIds.
     *   Callers validating only a NEW subset (task 4.4's add-more endpoint)
     *   pass existing+new here, since a newly added subcategory may belong
     *   to a category the vendor already selected earlier, not one in this
     *   request's own $categoryIds.
     */
    public static function validate(
        Validator $validator,
        array $categoryIds,
        array $subcategoryIds,
        array $zoneIds,
        ?array $allowedCategoryIds = null,
    ): void {
        $allowedCategoryIds ??= $categoryIds;

        $activeCategoryIds = Category::query()
            ->whereIn('id', $categoryIds)->active()->pluck('id')->all();

        if (count($activeCategoryIds) !== count(array_unique($categoryIds))) {
            $validator->errors()->add('category_ids', 'One or more categories are invalid or inactive.');
        }

        $subcategories = Subcategory::query()
            ->whereIn('id', $subcategoryIds)->active()
            ->get(['id', 'category_id']);

        if ($subcategories->count() !== count(array_unique($subcategoryIds))) {
            $validator->errors()->add('subcategory_ids', 'One or more subcategories are invalid or inactive.');
        }

        // A subcategory implies its category — independently re-checked
        // here, not trusted from the client's own cascade-select UI.
        $categoryIdSet = array_flip($allowedCategoryIds);
        foreach ($subcategories as $subcategory) {
            if (! isset($categoryIdSet[$subcategory->category_id])) {
                $validator->errors()->add(
                    'subcategory_ids',
                    "Subcategory {$subcategory->id} was selected without its parent category."
                );
                break;
            }
        }

        // Leaf-only (SPEC section 8): a zone with children is navigation
        // only and is never itself a valid selection.
        $leafZoneIds = Zone::query()
            ->whereIn('id', $zoneIds)->active()
            ->whereDoesntHave('children')
            // Effective active status, same rule as ZoneMatcher: a leaf
            // under a draft city is never matched in search, so it must not
            // be subscribable either.
            ->where(fn ($query) => $query->whereNull('parent_id')
                ->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)))
            ->pluck('id')->all();

        if (count($leafZoneIds) !== count(array_unique($zoneIds))) {
            $validator->errors()->add(
                'zone_ids',
                'One or more zones are invalid, inactive, or not a leaf zone.'
            );
        }
    }
}
