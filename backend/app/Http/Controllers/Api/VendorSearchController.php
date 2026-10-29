<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOptionalAuthUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\VendorSearchRequest;
use App\Http\Resources\VendorSearchResource;
use App\Models\Subcategory;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\VendorSearchService;
use App\Support\Languages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public customer vendor search (SPEC section 4 item 4, task 5.3) — the
 * screen CustomerSubcategoriesController.selectSubcategory() (task 5.1)
 * navigates into once a subcategory is tapped.
 */
class VendorSearchController extends Controller
{
    use ResolvesOptionalAuthUser;

    public function __construct(private readonly VendorSearchService $service)
    {
    }

    public function index(VendorSearchRequest $request): JsonResponse
    {
        // Optional: an unauthenticated search still works exactly as
        // before (task 5.3), this only adds is_favorite when a valid
        // customer token happens to be presented (favorites task).
        $this->resolveOptionalUser($request);

        $result = $this->service->search(
            subcategoryId: (int) $request->input('subcategory_id'),
            lat: $request->hasPoint() ? (float) $request->input('latitude') : null,
            lng: $request->hasPoint() ? (float) $request->input('longitude') : null,
            pincode: $request->filled('pincode') ? $request->string('pincode')->toString() : null,
            perPage: (int) $request->input('per_page', 15),
            sort: $request->string('sort', VendorSearchService::SORT_RATING)->toString(),
        );

        $zone = $result['zone'];
        $paginator = $result['paginator'];

        // No zone matched the given point/pincode — not an error, mirrors
        // CustomerController::updateLocation()'s same outcome. No `meta`
        // block: nothing was paginated.
        if ($zone === null || $paginator === null) {
            return response()->json([
                'success' => true,
                'data' => ['zone' => null, 'vendors' => []],
                'error' => null,
            ]);
        }

        $this->attachServices($paginator->getCollection());

        return response()->json([
            'success' => true,
            'data' => [
                'zone' => $this->zoneResource($zone),
                'vendors' => VendorSearchResource::collection($paginator->getCollection())->resolve(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'error' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneResource(Zone $zone): array
    {
        return ['id' => $zone->id, 'name' => $zone->name];
    }

    /**
     * The service chips on each search card ("Gas Filling +2") — every
     * subcategory the vendor's active subscription covers, not just the
     * one searched for.
     *
     * One grouped query for the whole page rather than N — a page is at
     * most 50 vendors (VendorSearchRequest's per_page ceiling), so this
     * is one extra round trip regardless of list size, not one per row.
     * Attached as a plain `services` attribute via setAttribute(), which
     * VendorSearchResource reads straight through — it is not an Eloquent
     * relation, it is the one query's result sliced back onto each model.
     *
     * @param  Collection<int, Vendor>  $vendors
     */
    private function attachServices(Collection $vendors): void
    {
        $vendorIds = $vendors->pluck('id');

        if ($vendorIds->isEmpty()) {
            return;
        }

        $rows = DB::table('subscription_items')
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_items.subscription_id')
            ->join('subcategories', 'subcategories.id', '=', 'subscription_items.item_id')
            ->where('subscription_items.item_type', 'subcategory')
            ->whereIn('subscriptions.vendor_id', $vendorIds)
            ->whereNull('subscriptions.deleted_at')
            ->select(['subscriptions.vendor_id', 'subcategories.id', 'subcategories.name'])
            ->orderBy('subcategories.sort_order')
            ->orderBy('subcategories.name')
            ->get()
            ->groupBy('vendor_id');

        foreach ($vendors as $vendor) {
            $services = ($rows->get($vendor->id) ?? collect())
                // Query-builder rows carry the raw per-language JSON.
                ->map(fn ($row) => ['id' => $row->id, 'name' => Languages::pick($row->name)])
                ->values()
                ->all();

            $vendor->setAttribute('services', $services);
        }
    }
}
