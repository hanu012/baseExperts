<?php

namespace App\Services\Firebase;

use App\Models\Setting;
use App\Services\Fcm\ServiceAccountJwt;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Firebase Authentication calls this app makes, over plain HTTPS
 * (Identity Toolkit REST API) — same no-SDK approach as FcmClient, so
 * Http::fake() covers all of it.
 *
 * Two uses:
 *  - Password emails: Firebase sends the reset / "set your password" email
 *    and vouches for the link; PasswordResetWebController saves the new
 *    password (in Laravel and, through confirmReset, in Firebase).
 *  - Firebase sign-in (Settings → Sign-in = Firebase, see AuthProvider):
 *    signInWithPassword checks app passwords, and accounts are created /
 *    updated / deleted here to mirror the users table, which stays the
 *    record of every account.
 *
 * Two kinds of credential:
 *  - The Web API key (public; Settings or FIREBASE_WEB_API_KEY) for
 *    sendOobCode and resetPassword — the same calls a client app makes.
 *  - The FCM service account (FCM_* in .env) for the admin calls: creating
 *    the account and pointing Firebase's email link at our page. It needs
 *    the "Firebase Authentication Admin" role, which the default
 *    firebase-adminsdk account has.
 */
class FirebaseAuthClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const API = 'https://identitytoolkit.googleapis.com';

    public function webApiKey(): ?string
    {
        foreach ([Setting::get('firebase_web_api_key'), config('services.firebase.web_api_key')] as $key) {
            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->webApiKey() !== null
            && filled(config('services.fcm.project_id'))
            && filled(config('services.fcm.client_email'))
            && filled(config('services.fcm.private_key_base64'));
    }

    /**
     * Makes sure Firebase has an account for this email. An account that
     * already exists is fine.
     *
     * @throws RuntimeException
     */
    public function ensureUser(string $email): void
    {
        try {
            $this->createAccount($email);
        } catch (FirebaseAuthException $e) {
            if ($e->reason !== 'EMAIL_EXISTS') {
                throw $e;
            }
        }
    }

    /**
     * Like ensureUser(), but returns the account's uid — looked up when the
     * account already existed.
     *
     * @throws RuntimeException
     */
    public function ensureUid(string $email): string
    {
        try {
            return $this->createAccount($email);
        } catch (FirebaseAuthException $e) {
            if ($e->reason !== 'EMAIL_EXISTS') {
                throw $e;
            }
        }

        return $this->findUid($email)
            ?? throw new RuntimeException('Firebase reported the account exists but could not find it.');
    }

    /**
     * Creates an account and returns its uid. Without a password the owner
     * sets one through an emailed link.
     *
     * @throws FirebaseAuthException EMAIL_EXISTS among others
     */
    public function createAccount(string $email, ?string $password = null, ?string $displayName = null): string
    {
        $response = Http::withToken($this->accessToken())
            ->post(self::API.'/v1/projects/'.$this->projectId().'/accounts', array_filter([
                'email' => $email,
                'password' => $password,
                'displayName' => $displayName,
            ], fn ($value) => $value !== null && $value !== ''));

        $this->throwUnlessSuccessful($response, 'Firebase could not create the account');

        return (string) $response->json('localId');
    }

    /** The uid of the account with this email, or null when there is none. */
    public function findUid(string $email): ?string
    {
        $response = Http::withToken($this->accessToken())
            ->post(self::API.'/v1/projects/'.$this->projectId().'/accounts:lookup', [
                'email' => [$email],
            ]);

        $this->throwUnlessSuccessful($response, 'Firebase could not look up the account');

        $uid = $response->json('users.0.localId');

        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * Changes an account's email and/or password.
     *
     * @param  array{email?: string, password?: string}  $changes
     *
     * @throws FirebaseAuthException USER_NOT_FOUND among others
     */
    public function updateAccount(string $uid, array $changes): void
    {
        $response = Http::withToken($this->accessToken())
            ->post(self::API.'/v1/projects/'.$this->projectId().'/accounts:update', ['localId' => $uid] + $changes);

        $this->throwUnlessSuccessful($response, 'Firebase could not update the account');
    }

    /** Deletes an account. One already gone is fine. */
    public function deleteAccount(string $uid): void
    {
        $response = Http::withToken($this->accessToken())
            ->post(self::API.'/v1/projects/'.$this->projectId().'/accounts:delete', ['localId' => $uid]);

        if ($response->successful() || $this->errorCode($response) === 'USER_NOT_FOUND') {
            return;
        }

        $this->throwUnlessSuccessful($response, 'Firebase could not delete the account');
    }

    /**
     * Checks an email + password with Firebase, the same call a client app
     * makes to sign in. Returns the account's uid.
     *
     * @throws FirebaseAuthException wrong password / no such account
     *                               (INVALID_LOGIN_CREDENTIALS, or the older
     *                               INVALID_PASSWORD / EMAIL_NOT_FOUND)
     * @throws RuntimeException      Firebase could not be reached
     */
    public function signInWithPassword(string $email, string $password): string
    {
        $response = Http::timeout(10)
            ->post(self::API.'/v1/accounts:signInWithPassword?key='.urlencode($this->requireWebApiKey()), [
                'email' => $email,
                'password' => $password,
                'returnSecureToken' => true,
            ]);

        $this->throwUnlessSuccessful($response, 'Firebase sign-in failed');

        return (string) $response->json('localId');
    }

    /**
     * Imports accounts with their existing bcrypt hashes, so nobody has to
     * choose a new password. Returns the indexes Firebase refused.
     *
     * @param  array<int, array{localId: string, email: string, passwordHash: string, displayName?: string}>  $users
     * @return array<int, int>
     */
    public function importBcryptUsers(array $users): array
    {
        $response = Http::withToken($this->accessToken())
            ->post(self::API.'/v1/projects/'.$this->projectId().'/accounts:batchCreate', [
                'hashAlgorithm' => 'BCRYPT',
                'users' => $users,
            ]);

        $this->throwUnlessSuccessful($response, 'Firebase could not import the accounts');

        return array_map(fn (array $error): int => (int) $error['index'], $response->json('error') ?? []);
    }

    /** @throws FirebaseAuthException */
    private function throwUnlessSuccessful(Response $response, string $message): void
    {
        if ($response->failed()) {
            throw new FirebaseAuthException($this->errorCode($response), $message);
        }
    }

    /**
     * Points the link in Firebase's password-reset email at our page
     * (Firebase Console → Authentication → Templates → "Customize action
     * URL", done here so nobody has to remember to). Checked at most once a
     * day per URL; a failure is logged and the email still goes out — the
     * console setting can be made by hand instead.
     */
    public function ensureActionUrl(string $url): void
    {
        $cacheKey = 'firebase_action_url:'.sha1($url);

        if (Cache::get($cacheKey)) {
            return;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->patch(
                    self::API.'/admin/v2/projects/'.$this->projectId().'/config?updateMask=notification.sendEmail.callbackUri',
                    ['notification' => ['sendEmail' => ['callbackUri' => $url]]],
                );
        } catch (\Throwable $e) {
            Log::warning('Firebase action URL could not be set', ['error' => $e->getMessage()]);

            return;
        }

        if ($response->successful()) {
            Cache::put($cacheKey, true, now()->addDay());

            return;
        }

        Log::warning('Firebase action URL could not be set', [
            'status' => $response->status(),
            'reason' => $this->errorCode($response),
        ]);
    }

    /**
     * Firebase sends its password-reset email to this address.
     *
     * @throws RuntimeException
     */
    public function sendPasswordResetEmail(string $email): void
    {
        $response = Http::post(self::API.'/v1/accounts:sendOobCode?key='.urlencode($this->requireWebApiKey()), [
            'requestType' => 'PASSWORD_RESET',
            'email' => $email,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Firebase could not send the reset email: '.$this->errorCode($response));
        }
    }

    /**
     * The email a reset code belongs to, or null when the code is
     * invalid, expired or already used. Does not consume the code.
     */
    public function verifyResetCode(string $oobCode): ?string
    {
        $response = Http::post(self::API.'/v1/accounts:resetPassword?key='.urlencode($this->requireWebApiKey()), [
            'oobCode' => $oobCode,
        ]);

        return $this->emailFrom($response);
    }

    /**
     * Uses up the reset code and returns its email, or null when the code
     * is no good. Firebase's own copy of the password is set too; it is
     * never used to sign in — Laravel's is.
     */
    public function confirmReset(string $oobCode, string $newPassword): ?string
    {
        $response = Http::post(self::API.'/v1/accounts:resetPassword?key='.urlencode($this->requireWebApiKey()), [
            'oobCode' => $oobCode,
            'newPassword' => $newPassword,
        ]);

        return $this->emailFrom($response);
    }

    private function emailFrom(Response $response): ?string
    {
        if ($response->failed()) {
            return null;
        }

        $email = $response->json('email');

        return is_string($email) && $email !== '' && $response->json('requestType') !== 'VERIFY_EMAIL'
            ? $email
            : null;
    }

    /**
     * Google's error code, e.g. EMAIL_EXISTS or EXPIRED_OOB_CODE. Some come
     * with detail after it ("TOO_MANY_ATTEMPTS_TRY_LATER : Access to this
     * account…"); only the code is kept.
     */
    private function errorCode(Response $response): string
    {
        $message = (string) ($response->json('error.message') ?? $response->status());

        return trim(explode(' ', $message, 2)[0]);
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase_auth_access_token', now()->addMinutes(55), function (): string {
            $privateKeyPem = base64_decode((string) config('services.fcm.private_key_base64'), strict: true);

            if ($privateKeyPem === false || $privateKeyPem === '') {
                throw new RuntimeException('FCM_PRIVATE_KEY_BASE64 is missing or not valid base64.');
            }

            $now = time();

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => ServiceAccountJwt::sign([
                    'iss' => (string) config('services.fcm.client_email'),
                    'scope' => self::SCOPE,
                    'aud' => self::TOKEN_URL,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], $privateKeyPem),
            ]);

            $token = $response->json('access_token');

            if ($response->failed() || ! is_string($token) || $token === '') {
                throw new RuntimeException('Firebase service-account sign-in failed.');
            }

            return $token;
        });
    }

    private function projectId(): string
    {
        $projectId = config('services.fcm.project_id');

        if (blank($projectId)) {
            throw new RuntimeException('FCM_PROJECT_ID is not set.');
        }

        return (string) $projectId;
    }

    private function requireWebApiKey(): string
    {
        return $this->webApiKey() ?? throw new RuntimeException('The Firebase Web API key is not set.');
    }
}
