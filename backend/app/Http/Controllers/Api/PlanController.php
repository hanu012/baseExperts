<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * Every active plan with its quota, for plan-selection screens (SPEC
     * section 2.2 and 3.2).
     *
     * DELIBERATELY NOT PAGINATED, same reasoning as CategoryController: this
     * is bounded master data (three plans today), and the salesman/vendor
     * apps need the whole set in one request to render selection cards with
     * a live "X of Y selected" quota counter.
     *
     * Public: the vendor self-service flow shows plans before the vendor is
     * signed in.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->active()
            ->with('quota')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(PlanResource::collection($plans));
    }
}
