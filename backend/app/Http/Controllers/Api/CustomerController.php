<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateCustomerLocationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Zone;
use App\Services\ZoneMatcher;
use Illuminate\Http\JsonResponse;

/**
 * A customer's own record (SPEC section 4.2, task 4.6) — currently just
 * the location-detection endpoint the home screen header uses.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly ZoneMatcher $zoneMatcher)
    {
    }

    /**
     * Resolves and persists the customer's location in one call — GPS
     * point or pincode fallback, whichever the app submits. A pincode
     * that matches no zone is still saved (SPEC: "captured for expansion
     * planning"), so this never rejects on a failed match, only on a
     * malformed/empty payload (UpdateCustomerLocationRequest).
     */
    public function updateLocation(UpdateCustomerLocationRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;

        if ($customer === null) {
            return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
        }

        $zone = null;
        $changes = [];

        if ($request->hasPoint()) {
            $lat = (float) $request->input('latitude');
            $lng = (float) $request->input('longitude');

            $zone = $this->zoneMatcher->matchPoint($lat, $lng);
            $changes['latitude'] = $lat;
            $changes['longitude'] = $lng;
        }

        if ($request->filled('pincode')) {
            $pincode = $request->string('pincode')->toString();

            // A point match wins when both are present; only fall back to
            // the pincode match when there was no point to try.
            $zone ??= $this->zoneMatcher->matchPincode($pincode);
            $changes['pincode'] = $pincode;
        }

        $customer->fill($changes)->save();

        return ApiResponse::success([
            'customer' => [
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'pincode' => $customer->pincode,
            ],
            'zone' => $zone === null ? null : $this->zoneResource($zone),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneResource(Zone $zone): array
    {
        return ['id' => $zone->id, 'name' => $zone->name];
    }
}
