<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SubcategoryVendorCountsRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use App\Services\VendorSearchService;
use App\Services\ZoneMatcher;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        private readonly ZoneMatcher $zoneMatcher,
        private readonly VendorSearchService $vendorSearchService,
    ) {
    }

    /**
     * The full active category tree, consumed by all three apps.
     *
     * DELIBERATELY NOT PAGINATED, against CLAUDE.md's "paginate every list
     * endpoint" rule. This is cache-on-launch master data, not a feed: the
     * customer app draws its browse grid from the whole tree, and the vendor
     * and salesman apps need all of it to render subscription selection with
     * a live "X of Y selected" counter. Paging would mean several round trips
     * before the first screen could draw, and nesting subcategories inside a
     * paginated parent list makes the meta block ambiguous.
     *
     * Public: no token required. Customers browse before signing in.
     */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->active()
            ->with([
                'subcategories' => fn ($query) => $query
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(CategoryResource::collection($categories));
    }

    /**
     * "X vendors" per subcategory on the customer subcategories screen
     * (SPEC section 4 item 3's redesign) — not folded into index() above:
     * that response is cache-on-launch master data shared by all three
     * apps, and a count is both zone-dependent and customer-specific in
     * a way the tree itself is not.
     *
     * Public, no token required, same as index() — the subcategories
     * screen is reached before sign-in like the rest of browse.
     */
    public function vendorCounts(Category $category, SubcategoryVendorCountsRequest $request): JsonResponse
    {
        $subcategoryIds = $category->subcategories()->active()->pluck('id')->all();

        $zone = null;

        if ($request->hasPoint()) {
            $zone = $this->zoneMatcher->matchPoint(
                (float) $request->input('latitude'),
                (float) $request->input('longitude'),
            );
        }

        if ($zone === null && $request->filled('pincode')) {
            $zone = $this->zoneMatcher->matchPincode($request->string('pincode')->toString());
        }

        // No location yet, or it matched no defined zone — not an error
        // (mirrors VendorSearchController's own "no match" outcome): the
        // grid still renders, every count is just zero.
        $counts = $zone === null
            ? []
            : $this->vendorSearchService->countBySubcategory($subcategoryIds, $zone);

        // Every subcategory gets an entry, zero by default, so the app
        // never has to treat "absent from the map" and "zero vendors" as
        // two different states.
        //
        // array_replace(), NOT array_merge(): both preserve string keys
        // the same way, but array_merge() silently RENUMBERS purely
        // integer keys instead of overwriting them — a subcategory id of
        // 61 would come back keyed "0" in the merged array. Subcategory
        // ids are exactly that (purely integer), so this is not a
        // hypothetical difference.
        $countsBySubcategory = array_replace(array_fill_keys($subcategoryIds, 0), $counts);

        return ApiResponse::success([
            'zone' => $zone === null ? null : ['id' => $zone->id, 'name' => $zone->name],
            // Cast to object: an int-keyed PHP array with no gaps encodes
            // as a JSON array, which would silently renumber subcategory
            // ids that are not already 0, 1, 2... in sequence.
            'counts' => (object) $countsBySubcategory,
        ]);
    }
}
