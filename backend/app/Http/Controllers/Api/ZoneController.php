<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ZoneResource;
use App\Http\Responses\ApiResponse;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;

class ZoneController extends Controller
{
    /**
     * The full active zone tree — top-level zones with their active leaf
     * children — for salesman/vendor zone selection (SPEC section 8).
     *
     * Filtering both levels through `active()` (same pattern as
     * CategoryController/subcategories) is what implements SPEC section 8's
     * "effective active status" rule: a child only appears here if it is
     * itself active AND its parent query already passed the active filter,
     * i.e. `child.is_active AND parent.is_active`.
     *
     * DELIBERATELY NOT PAGINATED — same master-data reasoning as
     * /api/categories and /api/plans.
     *
     * Public: the vendor self-service flow shows zones before sign-in.
     */
    public function index(): JsonResponse
    {
        $zones = Zone::query()
            ->whereNull('parent_id')
            ->active()
            // A city whose sub-zones are all still drafts is left out. It
            // is not a leaf (ZoneMatcher and the subscribe validator both
            // count inactive children), but with only active children
            // loaded below, ZoneResource would report it as one and the
            // apps would offer a zone the server then rejects.
            ->where(fn ($query) => $query
                ->whereDoesntHave('children')
                ->orWhereHas('children', fn ($child) => $child->active()))
            ->with([
                'children' => fn ($query) => $query
                    ->active()
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ZoneResource::collection($zones));
    }
}
