<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuthProvider;
use App\Services\Firebase\FirebaseAuthClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The "set a new password" page that reset emails link to — opened in the
 * phone's browser from the email, not inside an app.
 *
 *  - Laravel link:  /password/reset/{token}?email=…   (password.reset — the
 *    route name Laravel's ResetPassword email builds its link from)
 *  - Firebase link: /password/firebase-action?mode=resetPassword&oobCode=…
 *    (Firebase's email links here once its action URL points at us; see
 *    FirebaseAuthClient::ensureActionUrl)
 *
 * Either way the new password is saved in Laravel — the only password the
 * apps ever sign in with — via User::resetPasswordFromEmailLink(). Same
 * strength rules as changing it in the app (Password::defaults()).
 *
 * The same link is also the "set your password" email for an account
 * someone else created (AccountInviteService). Such an account is still on
 * a password it did not choose (must_change_password), so the pages say
 * "set your password" rather than "reset" — `firstTime` in the views.
 */
class PasswordResetWebController extends Controller
{
    // ── Laravel link ─────────────────────────────────────────────────────

    public function showLaravel(Request $request, string $token): View
    {
        $email = (string) $request->query('email');

        return view('auth.password-reset', [
            'action' => route('password.update'),
            'hidden' => ['token' => $token, 'email' => $email],
            'email' => $email,
            'firstTime' => self::isFirstTime($email),
        ]);
    }

    public function resetLaravel(Request $request, AuthProvider $auth): View|RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $firstTime = false;
        $appLink = null;

        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password) use ($auth, &$firstTime, &$appLink) {
                // Read before the reset clears it.
                $firstTime = (bool) $user->must_change_password;
                $appLink = self::appLinkFor($user);
                $user->resetPasswordFromEmailLink($password);
                // Only does anything with Firebase sign-in on.
                $auth->pushPassword($user, $password);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return view('auth.password-reset-expired');
        }

        return view('auth.password-reset-done', ['firstTime' => $firstTime, 'appLink' => $appLink]);
    }

    // ── Firebase link ────────────────────────────────────────────────────

    public function showFirebase(Request $request, FirebaseAuthClient $firebase): View
    {
        $oobCode = (string) $request->query('oobCode');

        // Only the reset email is handled here. Firebase uses the same URL
        // for its other actions (verify email, …), which this app never
        // sends.
        if ($request->query('mode') !== 'resetPassword' || $oobCode === '') {
            return view('auth.password-reset-expired');
        }

        $email = $firebase->verifyResetCode($oobCode);

        if ($email === null) {
            return view('auth.password-reset-expired');
        }

        return view('auth.password-reset', [
            'action' => route('password.firebase.update'),
            'hidden' => ['oobCode' => $oobCode],
            'email' => $email,
            'firstTime' => self::isFirstTime($email),
        ]);
    }

    public function resetFirebase(Request $request, FirebaseAuthClient $firebase): View
    {
        $data = $request->validate([
            'oobCode' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        // Uses the code up: a link works once.
        $email = $firebase->confirmReset($data['oobCode'], $data['password']);

        if ($email === null) {
            return view('auth.password-reset-expired');
        }

        // An email Firebase vouched for but with no live account here
        // (deleted since the email was sent) changes nothing — same page,
        // so the outcome does not reveal which.
        $user = User::query()->where('email', $email)->first();
        $firstTime = (bool) $user?->must_change_password;
        $user?->resetPasswordFromEmailLink($data['password']);

        return view('auth.password-reset-done', [
            'firstTime' => $firstTime,
            'appLink' => $user !== null ? self::appLinkFor($user) : null,
        ]);
    }

    /**
     * Opens the user's app on the phone this page is on, at its sign-in
     * screen (nobody is signed in yet, so its start screen is the login).
     * Each app registers its own scheme — AndroidManifest.xml, from the
     * flavour's `appLinkScheme` in build.gradle.kts. Null for admins, who
     * have no app.
     */
    private static function appLinkFor(User $user): ?string
    {
        return match ($user->role) {
            UserRole::Vendor => 'smvendor://login',
            UserRole::Customer => 'smcustomer://login',
            UserRole::Salesman => 'smsalesman://login',
            default => null,
        };
    }

    /** Whether this email's account has never chosen its own password. */
    private static function isFirstTime(string $email): bool
    {
        return $email !== ''
            && (bool) User::query()->where('email', $email)->value('must_change_password');
    }
}
