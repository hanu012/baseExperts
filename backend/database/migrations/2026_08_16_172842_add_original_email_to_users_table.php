<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds a deleted account's real email so a restore can put it back.
 *
 * Deleting a user rewrites `email` to a tombstone
 * (deleted-{id}-{timestamp}@deleted.local) so the address is freed for
 * re-registration — see the Phase 5.6 decision in PROGRESS.md. Without
 * somewhere to keep the original, that rewrite is one-way and a restored
 * account comes back unusable, with an address nobody can sign in as.
 *
 * Why a column rather than the alternatives:
 *
 *  - Encoding the original into the tombstone's local part would collide with
 *    the 255-char limit and needs parsing to reverse — a format to get wrong.
 *  - Reading it back out of audit_logs makes restore depend on log retention
 *    and on parsing history, which is not what an append-only log is for.
 *
 * A column sits on the same row, so the rename and the restore are one
 * atomic update.
 *
 * Deliberately NOT unique: it mirrors an address that has been released, and
 * two tombstoned rows could legitimately hold the same one if an address were
 * registered, deleted, re-registered and deleted again. The live-uniqueness
 * guarantee stays where it belongs, on `email`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('original_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('original_email');
        });
    }
};
