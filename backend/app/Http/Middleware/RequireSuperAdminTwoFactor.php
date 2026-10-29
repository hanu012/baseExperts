<?php

namespace App\Http\Middleware;

use App\Filament\SuperAdmin\Pages\TwoFactorChallenge;
use App\Filament\SuperAdmin\Pages\TwoFactorSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step login for every page of the super-admin panel.
 *
 *  - Not set up yet       → the setup page (it is required, not optional).
 *  - Set up, not verified → the code page, then back where they were going.
 *  - Verified, but idle for IDLE_MINUTES → the code page again. The /admin
 *    session itself is untouched; only this panel asks again.
 *
 * The password login is the shared one (/admin and /super-admin use the
 * same guard); this sits on top of it for the super-admin panel only.
 */
class RequireSuperAdminTwoFactor
{
    public const VERIFIED_AT = 'super_admin.two_factor_verified_at';

    public const LAST_SEEN = 'super_admin.last_seen_at';

    public const INTENDED = 'super_admin.intended_url';

    public const IDLE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $onSetup = $request->routeIs(TwoFactorSetup::getRouteName());
        $onChallenge = $request->routeIs(TwoFactorChallenge::getRouteName());

        if (! $user->hasTwoFactorEnabled()) {
            return $onSetup ? $next($request) : redirect(TwoFactorSetup::getUrl());
        }

        if ($onSetup) {
            // Setup is finished; the page only shows its recovery codes once.
            return $next($request);
        }

        if (! self::isFresh()) {
            if ($onChallenge) {
                return $next($request);
            }

            if ($request->isMethod('GET')) {
                session()->put(self::INTENDED, $request->fullUrl());
            }

            return redirect(TwoFactorChallenge::getUrl());
        }

        session()->put(self::LAST_SEEN, now()->getTimestamp());

        return $next($request);
    }

    /** Verified in this session, and active within the idle limit. */
    public static function isFresh(): bool
    {
        $verifiedAt = session()->get(self::VERIFIED_AT);
        $lastSeen = session()->get(self::LAST_SEEN, $verifiedAt);

        return $verifiedAt !== null
            && $lastSeen !== null
            && now()->getTimestamp() - (int) $lastSeen <= self::IDLE_MINUTES * 60;
    }

    /** Marks this session as having passed the code check just now. */
    public static function markVerified(): void
    {
        $now = now()->getTimestamp();

        session()->put(self::VERIFIED_AT, $now);
        session()->put(self::LAST_SEEN, $now);
    }
}
