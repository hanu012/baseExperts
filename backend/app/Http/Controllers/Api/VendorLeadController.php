<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lead;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's own Leads tab (SPEC section 3 item 7, task 4.8) — every
 * customer who tapped Call or WhatsApp, plus the "Request a review"
 * action (SPEC section 3 item 8).
 */
class VendorLeadController extends Controller
{
    public function __construct(private readonly PushNotificationService $notifications)
    {
    }

    /**
     * The first real caller of ApiResponse::paginated() — leads are
     * exactly the "unbounded, growing collection" CLAUDE.md names as
     * needing real pagination, unlike the bounded master-data lists.
     */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        $paginator = Lead::where('vendor_id', $vendor->id)
            ->with(['customer.user', 'subcategory', 'zone', 'review'])
            ->latest('created_at')
            ->paginate((int) $request->input('per_page', 15));

        $paginator->getCollection()->transform(fn (Lead $lead) => (new LeadResource($lead))->resolve());

        return ApiResponse::paginated($paginator);
    }

    /**
     * Counts for the vendor home "Recent calls" card. "Today" and "this
     * week" are the platform's local day (India), not UTC — a call at 2 am
     * IST belongs to that day, not the previous one.
     */
    public function summary(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        $timezone = 'Asia/Kolkata';
        $todayStart = now($timezone)->startOfDay()->utc();
        $weekStart = now($timezone)->startOfDay()->subDays(6)->utc();

        $leads = Lead::query()->where('vendor_id', $vendor->id);

        $averageSeconds = (clone $leads)
            ->where('channel', 'call')
            ->whereNotNull('call_duration_seconds')
            ->where('created_at', '>=', now()->subDays(30))
            ->avg('call_duration_seconds');

        return ApiResponse::success([
            'calls_today' => (clone $leads)->where('channel', 'call')->where('created_at', '>=', $todayStart)->count(),
            'whatsapp_today' => (clone $leads)->where('channel', 'whatsapp')->where('created_at', '>=', $todayStart)->count(),
            'calls_last_7_days' => (clone $leads)->where('channel', 'call')->where('created_at', '>=', $weekStart)->count(),
            // Approximate, over the last 30 days; null with nothing to average.
            'average_call_seconds' => $averageSeconds === null ? null : (int) round((float) $averageSeconds),
        ]);
    }

    /**
     * SPEC section 3 item 8: "send a review request — scoped to a
     * specific lead only, not to an arbitrary customer." Once per lead,
     * ever (see the migration's own docblock) — rejects a second
     * request, a lead that already has a review, and a lead too old for
     * the customer to actually leave one, mirroring
     * StoreReviewRequest's own 30-day eligibility window rather than
     * letting the vendor ask for something the customer would just hit
     * a 422 trying to do.
     */
    public function requestReview(Request $request, Lead $lead): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null || $lead->vendor_id !== $vendor->id) {
            return ApiResponse::error('NOT_FOUND', 'Lead not found.', 404);
        }

        if ($lead->created_at->lt(now()->subDays(30))) {
            return ApiResponse::error(
                'REVIEW_WINDOW_EXPIRED',
                'This lead is more than 30 days old — the customer can no longer leave a review for it.',
                422
            );
        }

        if ($lead->review()->exists()) {
            return ApiResponse::error('ALREADY_REVIEWED', 'This lead already has a review.', 422);
        }

        if ($lead->review_requested_at !== null) {
            return ApiResponse::error(
                'ALREADY_REQUESTED',
                'A review has already been requested for this lead.',
                422
            );
        }

        $lead->update(['review_requested_at' => now()]);

        $this->notifications->notifyReviewRequested($vendor, $lead);

        return ApiResponse::success(new LeadResource($lead->load(['customer.user', 'subcategory', 'zone', 'review'])));
    }
}
