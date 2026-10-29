<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeadRequest;
use App\Http\Requests\RecordLeadDurationRequest;
use App\Http\Resources\CustomerCallResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lead;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC section 4 item 7, task 5.4 — the write the vendor-detail screen's
 * Call/WhatsApp buttons must complete and confirm BEFORE opening the
 * dialer/WhatsApp intent. See Lead's own docblock for why this table is
 * load-bearing well beyond this one screen.
 */
class LeadController extends Controller
{
    public function __construct(private readonly PushNotificationService $notifications) {}

    public function store(CreateLeadRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $request->integer('vendor_id'),
            'subcategory_id' => $request->integer('subcategory_id'),
            'zone_id' => $request->filled('zone_id') ? $request->integer('zone_id') : null,
            'channel' => $request->string('channel')->toString(),
        ]);

        // BUILD_PLAN 7.2 — "lead received," never allowed to break
        // this write: FcmChannel already swallows send failures
        // internally, so a bad token or FCM outage can't turn a
        // successful lead record into a failed response.
        $this->notifications->notifyLeadReceived($lead);

        return ApiResponse::success([
            'id' => $lead->id,
            'created_at' => $lead->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * The customer app reporting how long the call lasted — measured from
     * tapping Call to returning to the app, so approximate (see the
     * add_call_duration_to_leads migration).
     *
     * Only the caller's own Call lead, only once, and only within a few
     * hours of it — so a duration cannot be rewritten later or attached to
     * someone else's lead. Another customer's lead is a 404, not a 403.
     */
    public function recordDuration(RecordLeadDurationRequest $request, int $lead): JsonResponse
    {
        $customer = $request->user()->customer;

        $record = $customer === null
            ? null
            : Lead::query()->where('customer_id', $customer->id)->whereKey($lead)->first();

        if ($record === null) {
            return ApiResponse::error('NOT_FOUND', 'Lead not found.', 404);
        }

        if ($record->channel !== 'call') {
            return ApiResponse::error('NOT_A_CALL', 'Only a call has a duration.', 422);
        }

        if ($record->call_duration_recorded_at !== null) {
            // Idempotent for the app: a retry after a timeout is fine.
            return ApiResponse::success(['call_duration_seconds' => $record->call_duration_seconds]);
        }

        if ($record->created_at->lt(now()->subHours(Lead::DURATION_REPORT_WINDOW_HOURS))) {
            return ApiResponse::error('TOO_LATE', 'This call is too old to update.', 422);
        }

        $record->recordCallDuration($request->integer('duration_seconds'));

        return ApiResponse::success(['call_duration_seconds' => $record->call_duration_seconds]);
    }

    /**
     * Profile → My calls: the customer's own calls and WhatsApp contacts,
     * newest first.
     */
    public function myCalls(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $paginator = Lead::query()
            ->where('customer_id', $customer->id)
            ->with(['vendor', 'subcategory'])
            ->latest('created_at')
            ->paginate(min((int) $request->input('per_page', 15), 50));

        $paginator->getCollection()->transform(fn (Lead $lead) => (new CustomerCallResource($lead))->resolve());

        return ApiResponse::paginated($paginator);
    }
}
