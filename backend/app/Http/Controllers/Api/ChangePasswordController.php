<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuthProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Changing your own password while signed in (SPEC section 2.1).
 *
 * Distinct from the reset flow in task 0.3: that one proves identity with an
 * emailed token, which a salesman logging in for the first time does not
 * have. They have the temporary password an admin read to them, and a live
 * session.
 */
class ChangePasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, AuthProvider $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Checked by the sign-in provider, like auth/login (see AuthProvider).
        if (! $auth->checkPassword($user, $request->string('current_password')->toString())) {
            return ApiResponse::error(
                'INVALID_CURRENT_PASSWORD',
                'Your current password is incorrect.',
                422
            );
        }

        // Stops a "change" that changes nothing from clearing the flag, which
        // would defeat the forced-change requirement entirely.
        if (Hash::check($request->string('password')->toString(), $user->password)) {
            return ApiResponse::error(
                'PASSWORD_UNCHANGED',
                'Your new password must be different from your current one.',
                422
            );
        }

        $currentToken = $user->currentAccessToken();

        DB::transaction(function () use ($user, $request, $currentToken) {
            $user->forceFill([
                'password' => $request->string('password')->toString(),
                'must_change_password' => false,
            ])->save();

            // A password change is the standard response to a suspected
            // compromise, so every other device is signed out. The token
            // making this request survives, or the caller would be logged out
            // by their own success.
            $user->tokens()
                ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();
        });

        // Firebase sign-in on: the Firebase account gets the new password
        // too. Saved here first; a failed copy is caught up at next sign-in.
        $auth->pushPassword($user, $request->string('password')->toString());

        return ApiResponse::success([
            'user' => new UserResource($user->fresh()),
            'message' => 'Your password has been changed.',
        ]);
    }
}
