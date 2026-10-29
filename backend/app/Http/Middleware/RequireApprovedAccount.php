<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the API until an admin has approved the account (SPEC section 3.1).
 *
 * WHY THIS EXISTS: registration now hands back a working token on purpose —
 * an unapproved user has to be able to sign in far enough to be *told* they
 * are waiting, and to sign out again. That token is otherwise fully
 * privileged, so the restriction has to be enforced here. A client-side
 * check alone would mean anyone calling the API directly, or any future
 * client that forgets, operates unapproved.
 *
 * Mirrors RequirePasswordChange deliberately: appended to the whole api
 * group rather than tagged per route, because a gate that only covers the
 * routes somebody remembered to mark is not a gate.
 *
 * Five things stay open. logout, because trapping someone in a session
 * they cannot leave is worse than the risk being managed. GET /user,
 * because the pending screen reads it to find out when the decision lands.
 * POST broadcasting/auth, so that screen can subscribe to its own channel
 * and hear the decision the moment it is made.
 * Device-token registration, so the push telling them they were approved
 * has somewhere to arrive. And DELETE /user — someone who decides not to
 * wait should not need our permission to leave.
 *
 * The allowlist is matched on method *and* path: `api/user` alone would
 * also have opened the DELETE, and later the PATCH on
 * `api/user/preferences`, by accident rather than by decision.
 */
class RequireApprovedAccount
{
    /**
     * Method => paths reachable while unapproved.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        // vendors/me/documents: a pending vendor's KYC documents are what
        // the approval waits on (VendorKycService), so they must be able to
        // see and upload them. Still role:vendor on the route itself.
        'POST' => ['api/auth/logout', 'api/device-tokens', 'api/broadcasting/auth', 'api/vendors/me/documents'],
        'GET' => ['api/user', 'api/vendors/me/documents'],
        'DELETE' => ['api/device-tokens', 'api/user'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isApproved()) {
            return $next($request);
        }

        $allowed = self::ALLOWED[$request->method()] ?? [];

        if ($allowed !== [] && $request->is(...$allowed)) {
            return $next($request);
        }

        // Distinct codes per state so the app can route to the right screen
        // instead of guessing from a generic 403: a pending account waits,
        // a rejected one has nothing to wait for.
        if ($user->isRejected()) {
            return ApiResponse::error(
                'ACCOUNT_REJECTED',
                $user->approval_note !== null && $user->approval_note !== ''
                    ? $user->approval_note
                    : 'Your account was not approved. Please contact support.',
                403
            );
        }

        return ApiResponse::error(
            'ACCOUNT_PENDING_APPROVAL',
            'Your account is awaiting verification by an administrator.',
            403
        );
    }
}
