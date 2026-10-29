<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile photo for every app user — customer, vendor and salesman —
 * set from the profile screens (POST/DELETE /api/user/avatar). The disk is
 * stored with the path, same as vendor KYC and portfolio files, so photos
 * on the local disk and on R2 can coexist during a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_disk')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('avatar_disk');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_disk', 'avatar_path']);
        });
    }
};
