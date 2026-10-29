<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped permissions for sub-admins (SPEC section 5.16) — an admin who can
 * moderate reviews but not touch plans or payments.
 *
 * A flat JSON array of `module.action` strings. A super-admin holds the
 * single wildcard entry `["*"]`.
 *
 * FAILS CLOSED: null or [] grants nothing. An admin created without anyone
 * setting permissions can reach the panel shell but no resource, which is
 * noisy and fixable. The alternative — treating null as full access — would
 * silently make every half-configured admin a super-admin, and nothing in the
 * UI would say so.
 *
 * Backfill: existing admins become super-admins, because they were created
 * before scoping existed and were unrestricted in practice. Demoting them
 * silently would lock the current operator out of their own panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('permissions')
            ->update(['permissions' => json_encode(['*'])]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
