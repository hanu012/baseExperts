<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Firebase\FirebaseAuthClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Who checks app passwords: admin Settings → Sign-in.
 *
 *  - laravel  (default) — the users table, as always. Nothing here runs.
 *  - firebase — Firebase Authentication. The users row is still THE record
 *               of the account (role, approval, profile, tokens); Firebase
 *               holds a matching account (users.firebase_uid) that checks
 *               the password and sends the password emails.
 *
 * Firebase chosen but not set up → laravel, same rule as password resets.
 *
 * The apps do not change: they still call auth/login and get the same
 * Sanctum token back, so approval, the password-change gate, realtime and
 * every other route work exactly as before.
 *
 * KEEPING BOTH IN STEP. The local hash is updated on every password change
 * this server makes (sign-up, change-password, either reset page, a
 * successful Firebase sign-in), so it is never older than Firebase's. That
 * is what makes the fallbacks below safe:
 *  - Firebase says no, but the local password matches → the account was
 *    never moved to Firebase (or Firebase missed a change). Accepted, and
 *    the password is pushed to Firebase — accounts move over on their own,
 *    at their next sign-in, with no bulk migration required.
 *  - Firebase cannot be reached → the local password decides, so an outage
 *    never locks anyone out.
 */
class AuthProvider
{
    public const LARAVEL = 'laravel';

    public const FIREBASE = 'firebase';

    public const PROVIDERS = [self::LARAVEL, self::FIREBASE];

    public function __construct(private readonly FirebaseAuthClient $firebase) {}

    /** The admin's choice, before checking Firebase is set up. */
    public static function chosen(): string
    {
        return Setting::get('auth_provider', self::LARAVEL) === self::FIREBASE ? self::FIREBASE : self::LARAVEL;
    }

    public function usesFirebase(): bool
    {
        return self::chosen() === self::FIREBASE && $this->firebase->isConfigured();
    }

    /**
     * Whether this is the user's password.
     *
     * Always also checks the local hash on a Firebase refusal, so the two
     * calls take a similar time whether or not the account is in Firebase.
     */
    public function checkPassword(User $user, string $password): bool
    {
        if (! $this->usesFirebase()) {
            return Hash::check($password, $user->password);
        }

        try {
            $uid = $this->firebase->signInWithPassword($user->email, $password);
        } catch (\Throwable $e) {
            // A refusal (FirebaseAuthException) or no answer at all — either
            // way the local password decides. See the class docblock.
            if (! $e instanceof Firebase\FirebaseAuthException) {
                Log::warning('Firebase sign-in unreachable; checked the local password instead', [
                    'user_id' => $user->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }

            if (! Hash::check($password, $user->password)) {
                return false;
            }

            if ($e instanceof Firebase\FirebaseAuthException) {
                $this->pushPassword($user, $password);
            }

            return true;
        }

        $this->remember($user, $uid, $password);

        return true;
    }

    /**
     * Copies a password this server just set to Firebase — creating the
     * account there if it has none yet. Never throws: the local password is
     * already saved, and the next sign-in pushes it again if this fails.
     */
    public function pushPassword(User $user, string $password): void
    {
        if (! $this->usesFirebase()) {
            return;
        }

        try {
            if ($user->firebase_uid !== null) {
                try {
                    $this->firebase->updateAccount($user->firebase_uid, ['password' => $password]);

                    return;
                } catch (Firebase\FirebaseAuthException $e) {
                    // Deleted in the Firebase console — recreated below.
                    if ($e->reason !== 'USER_NOT_FOUND') {
                        throw $e;
                    }
                }
            }

            try {
                $uid = $this->firebase->createAccount($user->email, $password, $user->name);
            } catch (Firebase\FirebaseAuthException $e) {
                if ($e->reason !== 'EMAIL_EXISTS') {
                    throw $e;
                }

                $uid = (string) $this->firebase->findUid($user->email);
                $this->firebase->updateAccount($uid, ['password' => $password]);
            }

            $this->linkUid($user, $uid);
        } catch (\Throwable $e) {
            Log::error('Password could not be copied to Firebase', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The user's Firebase account uid, creating a password-less account if
     * there is none (its owner sets the password through an emailed link).
     *
     * @throws \RuntimeException
     */
    public function ensureAccount(User $user): string
    {
        $uid = $user->firebase_uid ?? $this->firebase->ensureUid($user->email);
        $this->linkUid($user, $uid);

        return $uid;
    }

    /** Mirrors an email change to the user's Firebase account. Never throws. */
    public function syncEmail(User $user): void
    {
        if ($user->firebase_uid === null || ! $this->firebase->isConfigured()) {
            return;
        }

        $uid = $user->firebase_uid;
        $email = $user->email;

        // After commit: a rolled-back change must not reach Firebase.
        DB::afterCommit(fn () => rescue(
            fn () => $this->firebase->updateAccount($uid, ['email' => $email]),
            fn (\Throwable $e) => Log::error('Email change could not be copied to Firebase', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]),
            report: false,
        ));
    }

    /**
     * Removes the deleted user's Firebase account and unlinks it, so a
     * restored account starts clean (its next sign-in creates a new one).
     * Never throws.
     */
    public function forget(User $user): void
    {
        if ($user->firebase_uid === null) {
            return;
        }

        $uid = $user->firebase_uid;
        $user->forceFill(['firebase_uid' => null])->saveQuietly();

        if (! $this->firebase->isConfigured()) {
            return;
        }

        DB::afterCommit(fn () => rescue(
            fn () => $this->firebase->deleteAccount($uid),
            fn (\Throwable $e) => Log::error('Firebase account of a deleted user could not be removed', [
                'user_id' => $user->getKey(),
                'firebase_uid' => $uid,
                'error' => $e->getMessage(),
            ]),
            report: false,
        ));
    }

    /**
     * After a successful Firebase sign-in: link the account, and bring the
     * local hash up to date so a switch back to Laravel — or a Firebase
     * outage — still accepts this password.
     *
     * A password Firebase accepts but this server never set can only have
     * been chosen by the user through an emailed link that opened Firebase's
     * own page instead of ours (action URL not set) — so it counts as their
     * own choice and clears must_change_password, as our page would have.
     * Without this, a "set your password" invitee is sent straight back to
     * change-password after signing in with the password they just set.
     */
    private function remember(User $user, string $uid, string $password): void
    {
        $changes = [];

        if ($uid !== '' && $user->firebase_uid !== $uid) {
            $changes['firebase_uid'] = $uid;
        }

        if (! Hash::check($password, $user->password)) {
            $changes['password'] = $password;
            $changes['must_change_password'] = false;
        }

        if ($changes !== []) {
            // Quietly: bookkeeping, not a profile change — no audit row, no
            // email-sync listener.
            $user->forceFill($changes)->saveQuietly();
        }
    }

    private function linkUid(User $user, string $uid): void
    {
        if ($uid !== '' && $user->firebase_uid !== $uid) {
            $user->forceFill(['firebase_uid' => $uid])->saveQuietly();
        }
    }
}
