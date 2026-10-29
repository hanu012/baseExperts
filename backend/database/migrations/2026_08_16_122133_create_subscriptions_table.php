<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor subscriptions (SPEC section 6).
 *
 * Written by SubscriptionService inside a single transaction together with
 * subscription_items, payments and commissions — any failure rolls all of it
 * back.
 *
 * Price and dates are copied from the plan at purchase time rather than read
 * through the relation, so a later price change never rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // Restrict: a plan with subscriptions against it must not vanish.
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();

            // Who sold it. Null for self-service and admin-created.
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();

            // Commission is generated only when source = salesman and the
            // subscription was paid (SPEC section 10).
            $table->enum('source', ['salesman', 'self', 'admin']);

            $table->enum('status', ['active', 'grace', 'expired', 'cancelled'])->default('active');

            $table->date('start_date');
            $table->date('end_date');

            // Snapshot of what was charged, in paise.
            $table->unsignedBigInteger('price_paise');
            $table->unsignedInteger('duration_days');

            // Set only for a free trial; capped by an admin setting and
            // limited to one per phone number ever (SPEC section 2.2).
            $table->unsignedInteger('free_trial_days')->nullable();

            // Set when this subscription replaced another through an upgrade,
            // so credited unused days can be traced.
            $table->foreignId('previous_subscription_id')->nullable()
                ->constrained('subscriptions')->nullOnDelete();

            // SPEC section 6.3: required header, enforced by this unique
            // column. A double-tap on a bad network cannot create a second
            // subscription or a second commission.
            $table->uuid('idempotency_key')->unique();

            $table->timestamps();
            $table->softDeletes();

            // Vendor dashboard: current subscription and days remaining.
            $table->index(['vendor_id', 'status', 'end_date']);

            // The daily expiry job and the "expiring in 30 days" dashboard
            // count both scan on this.
            $table->index(['status', 'end_date']);

            $table->index(['salesman_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
