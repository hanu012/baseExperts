<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable settings (SPEC section 5.17). Key-value, one row per setting.
 *
 * This is not decoration: Phase 3's salesman flow reads its business rules
 * from here rather than hardcoding them —
 *
 *   free_trial_max_days              default 15  (SPEC section 2.2)
 *   free_grants_per_salesman_month   cap on free trials granted
 *   grace_period_days                Active -> Grace -> Expired (section 7)
 *   force_update_version             minimum app version
 *   maintenance_mode                 toggle
 *
 * `value` is text and `type` says how to cast it, so an admin form can render
 * the right control and the reader can coerce safely. Keeping the type
 * alongside the value avoids a second lookup table for six settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->text('value')->nullable();

            $table->enum('type', ['string', 'integer', 'boolean', 'json'])->default('string');

            // Grouping for the admin Settings screen, e.g. subscriptions, app.
            $table->string('group')->default('general');

            $table->string('label')->nullable();
            $table->text('description')->nullable();

            // False for values the admin UI must not expose, such as anything
            // set by deploy rather than by hand.
            $table->boolean('is_editable')->default(true);

            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
