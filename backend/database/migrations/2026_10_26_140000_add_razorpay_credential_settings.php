<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Razorpay credentials, editable on Settings → Payments
 * (App\Support\RazorpayCredentials reads them, with .env as a fallback).
 *
 * Not in SettingSeeder, for the same reason as the Google Maps keys: the
 * seeder's updateOrCreate would blank out credentials an admin saved.
 *
 * The key id is not secret (it appears on every checkout), so it is plain
 * text. The key secret and webhook secret are stored encrypted (type
 * `encrypted`, see Setting::get()) and are write-only on the page.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key' => 'razorpay_key_id',
            'type' => 'string',
            'label' => 'Razorpay key id',
            'description' => 'rzp_test_… or rzp_live_… from Razorpay Dashboard → Account & Settings → API keys.',
        ],
        [
            'key' => 'razorpay_key_secret',
            'type' => 'encrypted',
            'label' => 'Razorpay key secret',
            'description' => 'The secret paired with the key id. Server-side only.',
        ],
        [
            'key' => 'razorpay_webhook_secret',
            'type' => 'encrypted',
            'label' => 'Razorpay webhook secret',
            'description' => 'The secret entered on the webhook in the Razorpay dashboard — not the key secret.',
        ],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            if (DB::table('settings')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('settings')->insert($row + [
                'value' => null,
                'group' => 'payments',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', array_column(self::ROWS, 'key'))
            ->delete();
    }
};
