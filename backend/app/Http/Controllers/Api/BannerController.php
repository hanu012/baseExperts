<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Banner\ListBannersRequest;
use App\Http\Resources\BannerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

/**
 * Public banner serving + click tracking (SPEC section 5 item 5) —
 * the minimal API that makes `click_count` a real counter rather
 * than an admin-only field nobody can move. Deliberately does NOT
 * include any Flutter-side display work: SPEC never specifies where
 * or how a banner renders in any of the three app flows (grep the
 * whole document — "banner" appears exactly once, in the admin
 * module list), so that's flagged as a gap for a future task rather
 * than guessed at here, the same way the cms_pages gap was flagged.
 */
class BannerController extends Controller
{
    /**
     * DELIBERATELY NOT PAGINATED, same reasoning as CategoryController
     * — a handful of currently-live banners for one app/slot, not a
     * growing feed. Public: no token required, matching every other
     * master-data-shaped read.
     */
    public function index(ListBannersRequest $request): JsonResponse
    {
        $banners = Banner::query()
            ->serving($request->string('target_app')->toString(), $request->string('position')->value() ?: null)
            ->get();

        return ApiResponse::success(BannerResource::collection($banners));
    }

    /**
     * Atomic increment — never read-modify-write, which would lose
     * clicks under concurrent taps.
     */
    public function click(Banner $banner): JsonResponse
    {
        $banner->increment('click_count');

        return ApiResponse::success(['message' => 'Recorded.']);
    }
}
