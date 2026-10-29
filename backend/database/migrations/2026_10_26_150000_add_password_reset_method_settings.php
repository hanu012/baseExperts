<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who sends the "Forgot password?" email, chosen on Settings → Password
 * reset (see App\Services\PasswordResetService):
 *
 *  - laravel  — Laravel emails a reset link to a page on this server.
 *               The default.
 *  - firebase — Firebase Authentication sends the email; its link opens
 *               a page on this server that confirms the code with
 *               Firebase and sets the Laravel password.
 *
 * Not in SettingSeeder: its updateOrCreate would reset an admin's choice.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key' => 'password_reset_method',
            'value' => 'laravel',
            'type' => 'string',
            'label' => 'Password reset method',
            'description' => 'laravel or firebase — who sends the "Forgot password?" email.',
        ],
        [
            'key' => 'firebase_web_api_key',
            'value' => null,
            'type' => 'string',
            'label' => 'Firebase Web API key',
            'description' => 'Public key from Firebase Console → Project settings → General. Used for Firebase password reset emails.',
        ],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            if (DB::table('settings')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('settings')->insert($row + [
                'group' => 'auth',
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
