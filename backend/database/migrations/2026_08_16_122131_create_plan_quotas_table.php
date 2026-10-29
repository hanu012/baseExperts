<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan limits, kept in their own table so quota rules can grow without
 * widening `plans`.
 *
 * These are the numbers SubscriptionService validates against on every
 * subscribe, upgrade and add-on (SPEC section 6.2) — a request for six
 * categories on a five-category plan is rejected with 422.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_quotas', function (Blueprint $table) {
            $table->id();

            // Unique: exactly one quota row per plan.
            $table->foreignId('plan_id')->unique()->constrained('plans')->cascadeOnDelete();

            $table->unsignedInteger('max_categories');
            $table->unsignedInteger('max_subcategories');
            $table->unsignedInteger('max_zones');
            $table->unsignedInteger('max_photos');
            $table->unsignedInteger('max_videos');

            // Sort tier for customer search results — higher tiers rank first,
            // before rating and recency (SPEC section 4.5).
            $table->unsignedInteger('priority_rank')->default(0);

            $table->timestamps();

            $table->index('priority_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_quotas');
    }
};
