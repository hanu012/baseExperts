<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a subscription actually bought: the vendor's selected categories,
 * subcategories and zones, all capped by the plan's quota (SPEC section 6).
 *
 * This is the single source of truth for what a vendor offers and where —
 * there is no separate vendor_categories or vendor_zones table, because the
 * selection only exists within a subscription's quota.
 *
 * TRADE-OFF: `item_id` carries no foreign key, because it points at three
 * different tables depending on `item_type`. Deleting a category or zone can
 * therefore orphan rows here. Whatever deletes master data must clear the
 * matching items in the same transaction; a nightly integrity check is
 * cheap insurance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            $table->enum('item_type', ['category', 'subcategory', 'zone']);
            $table->unsignedBigInteger('item_id');

            $table->timestamps();

            // Quota counting reads this, and it stops the same selection being
            // recorded twice.
            $table->unique(['subscription_id', 'item_type', 'item_id']);

            // The hot path: customer search resolves "which subscriptions
            // cover this subcategory" and "which cover this zone" from here.
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
