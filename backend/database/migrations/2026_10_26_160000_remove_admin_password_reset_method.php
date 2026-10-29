<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Password resets happen only in the apps now (an emailed link); the
 * admin-handled "Admin request" method and the panel's Reset requests page
 * are gone. A database that had saved "admin" — or the earlier version of
 * 2026_10_26_150000 that defaulted to it — moves to the Laravel email.
 *
 * The password_reset_requests table is left in place, as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'password_reset_method')
            ->whereNotIn('value', ['laravel', 'firebase'])
            ->update([
                'value' => 'laravel',
                'description' => 'laravel or firebase — who sends the "Forgot password?" email.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Nothing to restore: "admin" no longer exists as a method.
    }
};
