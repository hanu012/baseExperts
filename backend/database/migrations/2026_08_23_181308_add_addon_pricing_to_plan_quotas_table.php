<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan, per-resource add-on unit pricing (SPEC section 3 item 6 /
 * section 6, task 4.7) — "+2 categories" needs a server-side price to
 * charge, and this table is already the per-plan, per-resource limits
 * table, the natural place for per-resource add-on pricing too rather
 * than a new table just for prices.
 *
 * Entered directly in paise via the admin form (no rupees-conversion
 * field, unlike `plans.price_rupees`) — a deliberate simplification;
 * see PROGRESS.md's task 4.7 entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_quotas', function (Blueprint $table) {
            $table->unsignedInteger('addon_price_per_category_paise')->default(0);
            $table->unsignedInteger('addon_price_per_subcategory_paise')->default(0);
            $table->unsignedInteger('addon_price_per_zone_paise')->default(0);
            $table->unsignedInteger('addon_price_per_photo_paise')->default(0);
            $table->unsignedInteger('addon_price_per_video_paise')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('plan_quotas', function (Blueprint $table) {
            $table->dropColumn([
                'addon_price_per_category_paise',
                'addon_price_per_subcategory_paise',
                'addon_price_per_zone_paise',
                'addon_price_per_photo_paise',
                'addon_price_per_video_paise',
            ]);
        });
    }
};
