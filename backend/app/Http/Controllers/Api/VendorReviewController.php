<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyToReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\VendorReviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's own Reviews tab (SPEC section 3 item 8, task 4.8) — view
 * every review on their own listing (hidden or not, see
 * VendorReviewResource) and reply to them.
 */
class VendorReviewController extends Controller
{
    /**
     * Unfiltered, unlike the customer-facing detail endpoint — a
     * vendor should see a review an admin hid, not have it silently
     * vanish from their own view.
     */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        $paginator = Review::where('vendor_id', $vendor->id)
            ->with('customer.user')
            ->latest('created_at')
            ->paginate((int) $request->input('per_page', 15));

        $paginator->getCollection()->transform(fn (Review $review) => (new VendorReviewResource($review))->resolve());

        return ApiResponse::paginated($paginator);
    }

    public function reply(ReplyToReviewRequest $request, Review $review): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null || $review->vendor_id !== $vendor->id) {
            return ApiResponse::error('NOT_FOUND', 'Review not found.', 404);
        }

        $review->update([
            'vendor_reply' => $request->string('reply')->toString(),
            'replied_at' => now(),
        ]);

        return ApiResponse::success(new ReviewResource($review->load('customer.user')));
    }
}
