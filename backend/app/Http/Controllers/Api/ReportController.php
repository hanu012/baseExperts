<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportVendorRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Report;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;

/**
 * Report vendor (SPEC section 4 item 10) — minimal by design. See the
 * `reports` migration's docblock for why this stays a flat write with no
 * status/lifecycle: that's Phase 6's Support Tickets module (SPEC
 * section 5.15).
 */
class ReportController extends Controller
{
    /**
     * A second report from the same customer against the same vendor is
     * a no-op success, not a 422 — matches favorites' idempotent-toggle
     * spirit, and the caller has no way to tell "already reported" apart
     * from "reported just now" without a status field anyway.
     */
    public function store(Vendor $vendor, ReportVendorRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        Report::firstOrCreate(
            ['customer_id' => $customer->id, 'vendor_id' => $vendor->id],
            ['reason' => $request->string('reason')->toString()]
        );

        return ApiResponse::success(['message' => 'Thanks — this vendor has been reported for review.'], 201);
    }
}
