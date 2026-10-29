<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quota purchased on top of a subscription's base plan (SPEC section 3
 * item 6 / section 6, task 4.7) — "+2 categories" without changing the
 * base plan. One row per purchase event, matching this codebase's
 * existing pattern of a dedicated row per priced business event
 * (payments, commissions) rather than counters bolted onto
 * `subscriptions` directly — keeps a real purchase history for admin
 * reporting.
 *
 * Scoped to `subscription_id`, not the vendor: add-ons do NOT carry
 * forward across a plan change, exactly like `subscription_items`
 * don't either — a vendor who upgrades starts the new subscription's
 * add-ons at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            $table->enum('resource', ['categories', 'subcategories', 'zones', 'photos', 'videos']);
            $table->unsignedInteger('quantity');

            // Snapshot of what was actually charged for this purchase —
            // plan_quotas' unit price can change later without rewriting
            // history, same reasoning subscriptions.price_paise already
            // follows.
            $table->unsignedBigInteger('price_paise');

            // SPEC section 6.3's Idempotency-Key requirement, same shape
            // as subscriptions.idempotency_key — a double-tap on a bad
            // network must not buy the add-on twice. Own dedicated
            // middleware (HandleIdempotentAddonPurchase), since
            // HandleIdempotentSubscription looks up Subscription rows
            // only, and an add-on purchase never creates one.
            $table->uuid('idempotency_key')->unique();

            $table->timestamps();

            $table->index(['subscription_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_addons');
    }
};
