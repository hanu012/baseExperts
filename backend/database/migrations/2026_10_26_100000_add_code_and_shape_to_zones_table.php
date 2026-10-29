<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zone code, and circle-shaped zones.
 *
 * A circle is stored twice on purpose: its centre and radius, so the admin
 * map can show and edit it as a circle, and a polygon approximating it in
 * the existing `polygon` column. Matching (ZoneMatcher, ST_Contains) keeps
 * reading `polygon` only and never needs to know a zone was drawn as a
 * circle.
 *
 * shape_type is a string rather than an ENUM so a later shape does not need
 * the raw ALTER TABLE ... MODIFY migration CLAUDE.md warns about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Nullable so the unique index tolerates the moment between
            // INSERT and Zone's created hook filling in a default code.
            $table->string('code', 50)->nullable()->unique()->after('slug');

            $table->string('shape_type', 20)->default('polygon')->after('polygon');
            $table->decimal('center_lat', 10, 7)->nullable()->after('shape_type');
            $table->decimal('center_lng', 10, 7)->nullable()->after('center_lat');
            $table->decimal('radius_km', 8, 3)->nullable()->after('center_lng');
        });

        // Existing zones get the same default a new zone would.
        DB::table('zones')->whereNull('code')->orderBy('id')->each(function (object $zone) {
            DB::table('zones')
                ->where('id', $zone->id)
                ->update(['code' => sprintf('ZONE-%03d', $zone->id)]);
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'shape_type', 'center_lat', 'center_lng', 'radius_km']);
        });
    }
};
