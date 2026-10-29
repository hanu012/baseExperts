<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceToken\RegisterDeviceTokenRequest;
use App\Http\Requests\DeviceToken\UnregisterDeviceTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

/**
 * FCM device registration (BUILD_PLAN 7.2). See DeviceToken's own
 * migration docblock for why `token` is upserted globally-uniquely
 * rather than scoped per caller.
 */
class DeviceTokenController extends Controller
{
    public function store(RegisterDeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString(),
                'last_used_at' => now(),
            ]
        );

        return ApiResponse::success(['message' => 'Registered.']);
    }

    /**
     * Scoped to the caller's own token — a request naming a token
     * that isn't theirs (or doesn't exist) is still a success from
     * the client's point of view: the end state (that token isn't
     * registered to them) already holds either way.
     */
    public function destroy(UnregisterDeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $request->string('token')->toString())
            ->delete();

        return ApiResponse::success(['message' => 'Unregistered.']);
    }
}
