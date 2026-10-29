<?php

namespace App\Services\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FCM HTTP v1 client (BUILD_PLAN 7.2). Deliberately built on Laravel's
 * own `Http` facade rather than a third-party SDK — both HTTP calls
 * this class makes (the OAuth2 token exchange and the actual
 * `messages:send` POST) go through it, so `Http::fake()` covers the
 * whole path in tests and `Http::assertSent()` can inspect the exact
 * payload that would have reached FCM, without any real credentials.
 *
 * No real Firebase project exists in this dev environment — see the
 * Before Launch Checklist. Every method here fails LOUDLY (throws)
 * when the FCM_* config is blank, rather than silently no-oping,
 * since a silent no-op would be indistinguishable from "sent
 * successfully but the device didn't get it."
 */
class FcmClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, mixed>  $message  The FCM `message` object
     *                                          minus `token`, e.g.
     *                                          ['notification' => [...], 'data' => [...]].
     */
    public function send(string $deviceToken, array $message): bool
    {
        $projectId = $this->requireConfig('project_id', 'FCM_PROJECT_ID');

        $response = Http::withToken($this->accessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => array_merge(['token' => $deviceToken], $message),
            ]);

        return $response->successful();
    }

    /**
     * Cached for its ~1 hour lifetime (55 min TTL, a safety margin
     * short of Google's own 3600s expiry) so a batch of pushes doesn't
     * re-authenticate per device.
     */
    public function accessToken(): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(55), fn () => $this->fetchAccessToken());
    }

    private function fetchAccessToken(): string
    {
        $jwt = $this->signedJwt();

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('FCM OAuth2 token exchange failed: '.$response->body());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('FCM OAuth2 token exchange returned no access_token.');
        }

        return $accessToken;
    }

    private function signedJwt(): string
    {
        $clientEmail = $this->requireConfig('client_email', 'FCM_CLIENT_EMAIL');
        $privateKeyBase64 = $this->requireConfig('private_key_base64', 'FCM_PRIVATE_KEY_BASE64');

        $privateKeyPem = base64_decode($privateKeyBase64, strict: true);

        if ($privateKeyPem === false) {
            throw new RuntimeException('FCM_PRIVATE_KEY_BASE64 is not valid base64.');
        }

        $now = time();

        return ServiceAccountJwt::sign([
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKeyPem);
    }

    private function requireConfig(string $key, string $envName): string
    {
        $value = config("services.fcm.{$key}");

        if (blank($value)) {
            throw new RuntimeException(
                "FCM is not configured: {$envName} is blank. See the Before Launch Checklist."
            );
        }

        return $value;
    }
}
