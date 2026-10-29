<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service account deletion (SPEC section 4 item 10 — "required for
 * app store compliance"). Reuses User::deleteWithTombstone() (task 2.1)
 * unchanged — its only prior caller was the admin Filament panel
 * (EditUser.php). This is purely a self-service entry point onto the
 * same mechanism: tombstone the email, revoke every token, soft-delete,
 * write an explicit audit entry. Leads/reviews/history are preserved,
 * same as the admin path.
 *
 * Mirrors ChangePasswordController's shape: verify the caller's own
 * password before a destructive self-action, same reasoning — a stolen
 * or borrowed device with a live session should not be enough on its
 * own to delete the real owner's account.
 */
class DeleteAccountController extends Controller
{
    public function __invoke(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            return ApiResponse::error('INVALID_PASSWORD', 'Your password is incorrect.', 422);
        }

        $user->deleteWithTombstone();

        return ApiResponse::success(['message' => 'Your account has been deleted.']);
    }
}
