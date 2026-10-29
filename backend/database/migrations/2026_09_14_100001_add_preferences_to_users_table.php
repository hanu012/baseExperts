<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user app preferences (SPEC section 2.5's profile screen).
 *
 * These already existed on the device (`PrefKeys.language`,
 * `PrefKeys.enableNotification`) with no server counterpart, so a reinstall
 * or a second device silently reset them. Storing them on `users` makes the
 * preference follow the account.
 *
 * `enable_notification` is a per-USER mute, deliberately separate from the
 * `device_tokens` table, which tracks per-DEVICE delivery targets: removing
 * a token means "this device is gone", muting means "this person does not
 * want to be pinged on any of them".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('language', 10)->default('en')->after('permissions');
            $table->boolean('enable_notification')->default(true)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['language', 'enable_notification']);
        });
    }
};
