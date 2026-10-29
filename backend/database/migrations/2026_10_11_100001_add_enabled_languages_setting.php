<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Settings page only saves keys that already have a row, so an existing
 * database needs this one inserted — the seeder alone would only reach a
 * fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'enabled_languages')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'enabled_languages',
            'value' => '["en","hi","gu"]',
            'type' => 'json',
            'group' => 'app',
            'label' => 'Enabled languages',
            'description' => 'Languages the apps offer in Profile → Language. English is always on.',
            'is_editable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'enabled_languages')->delete();
    }
};
