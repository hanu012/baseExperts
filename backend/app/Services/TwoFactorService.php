<?php

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;

/**
 * Two-step login for the super-admin panel: an authenticator-app code on
 * top of the password (RequireSuperAdminTwoFactor decides when it is asked
 * for).
 *
 * Setup is two steps on purpose: begin() stores a secret but leaves the
 * account unprotected until confirm() sees a correct code from it, so a
 * half-finished setup can never lock anyone out.
 *
 * Recovery codes are the way back in without the phone. Each works once.
 * Losing both phone and codes needs another super-admin ("Reset two-step
 * login" on the Admins screen) or `php artisan admin:reset-two-factor`.
 */
class TwoFactorService
{
    public const ISSUER = 'Service Marketplace Super Admin';

    private const RECOVERY_CODES = 8;

    /** Starts (or restarts) setup and returns the new secret. */
    public function begin(User $user): string
    {
        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /**
     * Finishes setup when the code matches the pending secret. Returns the
     * recovery codes to show once, or null when the code is wrong.
     *
     * @return array<int, string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if (blank($user->two_factor_secret)) {
            return null;
        }

        $step = Totp::verify($user->two_factor_secret, $code);

        if ($step === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
            'two_factor_last_step' => $step,
        ])->save();

        return $codes;
    }

    /**
     * Whether this is a valid code right now: an authenticator code not
     * used before, or an unused recovery code (which is then spent).
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = Totp::verify($user->two_factor_secret, $code);

        if ($step !== null) {
            // The same six digits twice — someone who watched or intercepted
            // the first use.
            if ($user->two_factor_last_step !== null && $step <= $user->two_factor_last_step) {
                return false;
            }

            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    /** Fresh recovery codes, replacing the old ones. */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /** Turns it off; the next super-admin sign-in sets it up again. */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> e.g. "k3f9q-2mxa7" */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
