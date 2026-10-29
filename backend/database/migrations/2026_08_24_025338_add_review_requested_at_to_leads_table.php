<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the vendor Leads tab's "Request a review" action (SPEC section
 * 3 item 8, task 4.8). Once per lead, ever — a lead is a single contact
 * event; asking again requires a genuinely new lead, not a cooldown
 * timer. No history table: nothing needs to know "requested twice",
 * since a second request is rejected outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('review_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('review_requested_at');
        });
    }
};
