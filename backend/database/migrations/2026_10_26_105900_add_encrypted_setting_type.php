<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * settings.type gains 'encrypted' (see Setting::get()), which the Google
 * Maps and Razorpay key migrations right after this one insert rows of.
 * A raw MODIFY because ->change() is unreliable on enum columns (CLAUDE.md).
 *
 * Idempotent: some local databases already had the value added by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE settings MODIFY type ENUM('string', 'integer', 'boolean', 'json', 'encrypted') NOT NULL DEFAULT 'string'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE settings MODIFY type ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string'");
    }
};
