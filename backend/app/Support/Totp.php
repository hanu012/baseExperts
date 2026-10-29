<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238) — the 6-digit codes from Google
 * Authenticator, Microsoft Authenticator, Authy and the like. Used for the
 * super-admin panel's two-step login.
 *
 * Written out here rather than pulled in as a package: it is ~60 lines of
 * standard HMAC-SHA1 arithmetic, and the defaults every authenticator app
 * assumes (SHA1, 6 digits, 30-second steps) are fixed below.
 */
class Totp
{
    private const DIGITS = 6;

    private const PERIOD = 30;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32 (what the user types into the app). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The time step a code is valid in, if it matches the secret at the
     * current step or one either side (clock drift). Null when wrong.
     *
     * Returning the step lets the caller refuse the same code twice.
     */
    public static function verify(string $secret, string $code, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', (string) $code)) {
            return null;
        }

        $step = intdiv($now ?? time(), self::PERIOD);

        foreach ([0, -1, 1] as $offset) {
            if (hash_equals(self::codeAt($secret, $step + $offset), $code)) {
                return $step + $offset;
            }
        }

        return null;
    }

    /** The code for a given time step. */
    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The otpauth:// link authenticator apps understand. Tapped on a phone,
     * it opens the app with the account filled in.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&digits='.self::DIGITS
            .'&period='.self::PERIOD;
    }

    /** "JBSWY3DPEHPK3PXP…" grouped in fours, easier to type by hand. */
    public static function formatForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';

        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
