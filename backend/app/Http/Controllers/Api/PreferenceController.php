<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdatePreferencesRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Per-user app preferences — language and the notification mute.
 *
 * Role-agnostic on purpose: all three apps show the same two switches, and
 * a salesman-only endpoint would be copied for vendors and customers within
 * a release. These used to live only in device storage (`PrefKeys.language`,
 * `PrefKeys.enableNotification`), so a reinstall silently reset them.
 *
 * The mute is a per-user flag, deliberately distinct from `device_tokens`:
 * deleting a token means "this device is gone", muting means "do not ping
 * this person on any device". PushNotificationService is what must honour
 * it; this endpoint only records the choice.
 */
class PreferenceController extends Controller
{
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->safe()->only(['language', 'enable_notification']));

        return ApiResponse::success([
            'language' => $user->language,
            'enable_notification' => $user->enable_notification,
        ]);
    }
}
