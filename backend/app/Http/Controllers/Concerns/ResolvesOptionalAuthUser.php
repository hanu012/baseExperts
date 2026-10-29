<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves the caller from a bearer token if one is present, without ever
 * requiring one — for public routes (vendor search/detail) that must
 * behave identically for a guest, but add caller-specific fields (e.g.
 * favorites' `is_favorite`) when a valid token happens to be presented.
 *
 * Deliberately NOT route-level `auth:sanctum` middleware: that guard
 * fails closed (401) on a missing or invalid token, which these routes —
 * public before this task and must stay public — must never do. There is
 * no other "optional auth" precedent in this codebase to follow; this
 * trait establishes it.
 *
 * Sets the user on the 'sanctum' guard itself (GuardHelpers::setUser())
 * rather than $request->setUserResolver() — a bare setUserResolver()
 * call is not enough here, because Laravel's own AuthServiceProvider
 * registers a `rebinding('request', ...)` callback that OVERWRITES
 * $request's resolver back to "defer to the auth guard" every time the
 * container's 'request' singleton is rebound (see
 * Illuminate\Auth\AuthServiceProvider::registerRequestRebindHandler()).
 * Since $request->user() already defers there by default, priming the
 * guard directly is both the correct fix and simpler than fighting that
 * rebind.
 */
trait ResolvesOptionalAuthUser
{
    protected function resolveOptionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if ($accessToken === null || $accessToken->tokenable === null) {
            return null;
        }

        /** @var User $user */
        $user = $accessToken->tokenable;

        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');

        return $user;
    }
}
