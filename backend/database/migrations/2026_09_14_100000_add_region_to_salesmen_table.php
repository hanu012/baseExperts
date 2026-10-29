<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The territory a salesman covers (SPEC section 2.5's profile screen).
 *
 * A free-text label, not a foreign key to `zones`: a salesman's patch is an
 * organisational fact ("Ahmedabad · Gujarat"), while a zone is a geometric
 * service area a vendor subscribes to. Tying the two would imply a salesman
 * can only sell inside drawn polygons, which is not how the channel works —
 * and would need a pivot for anyone covering several zones. Left nullable so
 * existing salesmen stay valid until an admin fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->string('region')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
