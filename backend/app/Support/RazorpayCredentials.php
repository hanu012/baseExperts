<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The three Razorpay credentials, read from admin Settings → Payments first
 * and from .env (config/services.php) when nothing has been saved there.
 *
 * Every Razorpay call goes through RazorpayClient, which reads them only
 * here, so the precedence rule lives in one place — same shape as MapKeys.
 */
class RazorpayCredentials
{
    public static function keyId(): ?string
    {
        return static::pick(Setting::get('razorpay_key_id'), config('services.razorpay.key_id'));
    }

    public static function keySecret(): ?string
    {
        return static::pick(Setting::get('razorpay_key_secret'), config('services.razorpay.key_secret'));
    }

    public static function webhookSecret(): ?string
    {
        return static::pick(Setting::get('razorpay_webhook_secret'), config('services.razorpay.webhook_secret'));
    }

    private static function pick(mixed $fromSettings, mixed $fromEnv): ?string
    {
        foreach ([$fromSettings, $fromEnv] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
