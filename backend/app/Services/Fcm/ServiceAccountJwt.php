<?php

namespace App\Services\Fcm;

use RuntimeException;

/**
 * Signs a Google service-account JWT (RS256), the first half of the
 * OAuth2 JWT-bearer flow HTTP v1 requires (BUILD_PLAN 7.2).
 *
 * Hand-rolled via openssl_sign() rather than a composer dependency —
 * PHP's openssl extension (already required by Laravel) does RS256
 * signing directly, and keeping this pure/deterministic (no HTTP, no
 * container, just claims + a PEM key in, a signature out) makes it
 * independently unit-testable against a fixed test keypair without
 * touching the network at all.
 */
class ServiceAccountJwt
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $claims, string $privateKeyPem): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException('FCM private key is not a valid PEM-encoded RSA key.');
        }

        $signature = '';
        $signed = openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Failed to sign the FCM service-account JWT.');
        }

        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
