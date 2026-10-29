<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Firebase\FirebaseAuthClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * "Forgot password?" from the apps — the only way a forgotten password is
 * reset. Admins never set or see a user's password; there is no reset in
 * the panel.
 *
 * Always an emailed link, sent whichever way admin Settings → Password
 * reset selects:
 *
 *  - laravel  — Laravel's password broker emails a link to
 *               /password/reset/{token} (PasswordResetWebController).
 *               The default.
 *  - firebase — Firebase Authentication emails the link; it opens
 *               /password/firebase-action, which confirms the code with
 *               Firebase and saves the password in Laravel.
 *
 * The new password is saved here either way (and in Firebase too, for the
 * Firebase link); see AuthProvider for who checks it at sign-in.
 *
 * An address with no account never gets this far: the request refuses it
 * with "No account found" (RequestPasswordResetRequest). The null check
 * below stays as a backstop.
 *
 * Firebase chosen but not set up → the Laravel email is used. A send that
 * fails is logged loudly; the user sees the usual answer and can ask again.
 */
class PasswordResetService
{
    public const LARAVEL = 'laravel';

    public const FIREBASE = 'firebase';

    public const METHODS = [self::LARAVEL, self::FIREBASE];

    public function __construct(private readonly FirebaseAuthClient $firebase) {}

    /**
     * The method in force: the admin's choice, or Laravel when Firebase is
     * chosen but not set up. With Firebase sign-in on (AuthProvider), always
     * Firebase — its reset page sets the password in Firebase and here.
     */
    public function method(): string
    {
        if (AuthProvider::chosen() === AuthProvider::FIREBASE && $this->firebase->isConfigured()) {
            return self::FIREBASE;
        }

        $chosen = Setting::get('password_reset_method', self::LARAVEL);

        return $chosen === self::FIREBASE && $this->firebase->isConfigured()
            ? self::FIREBASE
            : self::LARAVEL;
    }

    /**
     * Emails a reset link to this address if it has an account. Returns the
     * method in force.
     */
    public function request(string $email): string
    {
        $method = $this->method();

        $user = User::query()
            // Case-insensitive collation: "Ravi@X.com" finds ravi@x.com.
            // A deleted (tombstoned) account never matches.
            ->where('email', trim($email))
            ->first();

        if ($user === null) {
            return $method;
        }

        try {
            $this->sendLink($user);
        } catch (\Throwable $e) {
            Log::error('Password reset email could not be sent', [
                'method' => $method,
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        return $method;
    }

    /**
     * Emails this user the link, by the method in force. Throws when it
     * could not go — for callers that must not carry on without it
     * (sign-up: the account has no usable password until the link is used).
     *
     * @throws \Throwable
     */
    public function sendLink(User $user): void
    {
        $this->method() === self::FIREBASE
            ? $this->sendFirebaseLink($user)
            : $this->sendLaravelLink($user);
    }

    /**
     * The broker throttles a repeat within a minute itself (and reports
     * it rather than throwing); that is fine to ignore — an email went out
     * moments ago. Any other refusal means nothing was sent.
     */
    private function sendLaravelLink(User $user): void
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if (! in_array($status, [Password::RESET_LINK_SENT, Password::RESET_THROTTLED], true)) {
            throw new \RuntimeException("Password broker did not send the link: {$status}");
        }
    }

    private function sendFirebaseLink(User $user): void
    {
        $this->firebase->ensureActionUrl(route('password.firebase.show'));
        $this->firebase->ensureUser($user->email);
        $this->firebase->sendPasswordResetEmail($user->email);
    }

    /** The app's confirmation text. */
    public static function message(): string
    {
        return 'We have sent a link to set a new password to this email. Check your inbox and spam folder.';
    }
}
