<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google Maps keys, editable on Settings → Maps (MapKeys reads them, with
 * the .env values as a fallback).
 *
 * Inserted here and deliberately NOT in SettingSeeder: the seeder uses
 * updateOrCreate with fixed values, so re-running it would blank out
 * whatever key an admin had saved.
 *
 * google_maps_api_key is the server-side Places key and is stored
 * encrypted (type `encrypted`, see Setting::get()), so neither the
 * settings table nor its audit-log entries hold it in plain text.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key' => 'google_maps_browser_key',
            'type' => 'string',
            'label' => 'Google Maps browser key',
            'description' => 'Loads the zone map in the admin panel. Visible in the page, so restrict it by HTTP referrer in Google Cloud.',
        ],
        [
            'key' => 'google_maps_api_key',
            'type' => 'encrypted',
            'label' => 'Google Places server key',
            'description' => 'Used server-side for the zone map place search. Never sent to the browser.',
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
                'group' => 'maps',
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
