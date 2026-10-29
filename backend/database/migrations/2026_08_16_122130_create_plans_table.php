<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans (SPEC section 5.4). Quota fields live in plan_quotas,
 * one row per plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Integer paise, never float (CLAUDE.md). Rupees 999.00 = 99900.
            $table->unsignedBigInteger('price_paise');

            // The server computes end_date = now + duration_days; the client
            // never sends dates (SPEC section 6.1).
            $table->unsignedInteger('duration_days');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
