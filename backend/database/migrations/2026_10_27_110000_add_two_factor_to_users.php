<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step login for the super-admin panel (App\Support\Totp,
 * RequireSuperAdminTwoFactor). Only super-admins use it today.
 *
 *  - two_factor_secret          — encrypted; the authenticator key
 *  - two_factor_recovery_codes  — encrypted JSON of one-use backup codes
 *  - two_factor_confirmed_at    — set once a first code proved the app works;
 *                                 until then the secret is only a pending setup
 *  - two_factor_last_step       — the last code's time step, so the same
 *                                 6 digits cannot be used twice
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('firebase_uid');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
