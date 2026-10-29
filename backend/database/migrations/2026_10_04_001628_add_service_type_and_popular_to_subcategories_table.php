<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the customer subcategories screen (SPEC section 4 item 3): the
 * Installation/Repair/Maintenance filter chips and the "Popular" badge
 * both need a value to filter/sort on, and neither existed on this row
 * before — category/subcategory browsing had no notion of either.
 *
 * `service_type` is nullable rather than required: an admin renaming an
 * existing subcategory should not be blocked by a field the design only
 * just introduced, and the "All" filter chip already covers an
 * unclassified row correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->enum('service_type', ['installation', 'repair', 'maintenance'])
                ->nullable()
                ->after('slug');

            $table->boolean('is_popular')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'is_popular']);
        });
    }
};
