<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\CustomerReviewResource;
use App\Http\Resources\ReviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC section 9, task 5.5 — the customer-facing write and 24-hour edit
 * endpoints. Vendor replies live in VendorReviewController; admin hide/
 * unhide lives in the Filament ReviewResource.
 */
class ReviewController extends Controller
{
    /** The caller's own reviews, newest first — Profile → My reviews. */
    public function mine(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $paginator = Review::query()
            ->where('customer_id', $customer->id)
            ->with(['vendor', 'lead.subcategory'])
            ->latest('created_at')
            ->paginate(min((int) $request->input('per_page', 15), 50));

        $paginator->getCollection()->transform(fn (Review $review) => (new CustomerReviewResource($review))->resolve());

        return ApiResponse::paginated($paginator);
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $lead = $request->eligibleLead;

        $review = Review::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $lead->vendor_id,
            'rating' => $request->integer('rating'),
            'comment' => $request->input('comment'),
        ]);

        return ApiResponse::success(
            new ReviewResource($review->load('customer.user')),
            201
        );
    }

    public function update(UpdateReviewRequest $request, Review $review): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null || $review->customer_id !== $customer->id) {
            return ApiResponse::error('NOT_FOUND', 'Review not found.', 404);
        }

        if ($review->created_at->lt(now()->subHours(24))) {
            return ApiResponse::error(
                'EDIT_WINDOW_EXPIRED',
                'Reviews can only be edited within 24 hours of posting.',
                422
            );
        }

        $review->fill($request->only(['rating', 'comment']))->save();

        return ApiResponse::success(new ReviewResource($review->load('customer.user')));
    }
}
