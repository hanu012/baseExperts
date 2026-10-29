<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic zones (SPEC section 8). Self-referencing: a main zone such as
 * Ahmedabad contains sub-zones like Gota, Ranip, Sola, Paldi and Ambawadi.
 *
 * SRID NOTE — read before changing the polygon column.
 *
 * SPEC asks for SRID 4326. That cannot be declared on the column here:
 * MariaDB 10.4 rejects both MySQL 8's `POLYGON ... SRID 4326` and MariaDB
 * 10.7+'s `ref_system_id=4326` with a syntax error. Passing `srid: 4326` to
 * the Blueprint makes Laravel emit `ref_system_id=`, which fails this
 * migration outright on the local XAMPP database.
 *
 * So the column is a plain POLYGON and SRID 4326 is carried on the *values*
 * instead — always write geometry with ST_GeomFromText(..., 4326), which
 * MariaDB stores and returns via ST_SRID.
 *
 * Be aware MariaDB ignores the SRID when computing: ST_Contains works
 * correctly but treats coordinates as a flat plane. That is accurate enough
 * at city-zone scale, and diverges from MySQL 8's geodetic maths if
 * production ever runs MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();

            // Null for a main zone; set for a sub-zone.
            $table->foreignId('parent_id')->nullable()->constrained('zones')->nullOnDelete();

            $table->string('name');
            $table->string('slug');

            // NOT NULL because MySQL and MariaDB both refuse a SPATIAL INDEX
            // on a nullable column. Zones are therefore created with their
            // boundary already drawn, and start inactive (below) so a rough
            // outline can be refined before the zone goes live.
            $table->geometry('polygon', subtype: 'polygon');

            // Fallback matching when GPS is denied or the point falls outside
            // every polygon (SPEC sections 4.2 and 8).
            $table->string('pincode', 10)->nullable();

            // Draft flag: new zones are not matched against until switched on.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['parent_id', 'slug']);

            // The index behind ST_Contains(zone.polygon, POINT(lng, lat)).
            //
            // Unguarded: the test suite runs on MariaDB too (see phpunit.xml),
            // so the schema is identical in test and development. This does
            // mean the migration cannot run on SQLite at all — which is the
            // honest outcome, since SQLite has no ST_Contains either and
            // could never exercise zone matching.
            $table->spatialIndex('polygon');

            $table->index('pincode');
            $table->index(['parent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
