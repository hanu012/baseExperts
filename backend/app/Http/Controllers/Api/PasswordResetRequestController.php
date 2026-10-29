<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestPasswordResetRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

/**
 * "Forgot password?" from the apps: emails a reset link, sent by Laravel
 * or Firebase as admin Settings → Password reset selects (see
 * PasswordResetService). No password changes here — the user sets the new
 * one on the page the link opens.
 *
 * An email with no account is refused with a 422 field error on `email`
 * (see RequestPasswordResetRequest); `method` depends only on
 * configuration.
 */
class PasswordResetRequestController extends Controller
{
    public function __invoke(RequestPasswordResetRequest $request, PasswordResetService $resets): JsonResponse
    {
        $method = $resets->request($request->string('email')->toString());

        return ApiResponse::success([
            'method' => $method,
            'message' => PasswordResetService::message(),
        ]);
    }
}
