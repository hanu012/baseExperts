<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the API until a forced password change is done (SPEC section 2.1).
 *
 * WHY THIS EXISTS: the login response already contains a working token, so a
 * client-side redirect to the change-password screen is advisory only.
 * Anyone calling the API directly - or any future client that forgets the
 * check - would keep using the admin-chosen password indefinitely. The
 * requirement has to hold at the API or it does not really hold.
 *
 * Two routes stay open, and both are necessary: change-password because it is
 * the way out, and logout because trapping someone in a session they cannot
 * leave is worse than the risk being managed.
 *
 * Returns a distinct error code so the app can route to the right screen
 * rather than treating it as a generic 403.
 */
class RequirePasswordChange
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED = [
        'api/auth/change-password',
        'api/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->is(self::ALLOWED)) {
            return $next($request);
        }

        return ApiResponse::error(
            'PASSWORD_CHANGE_REQUIRED',
            'You must change your temporary password before continuing.',
            403
        );
    }
}
