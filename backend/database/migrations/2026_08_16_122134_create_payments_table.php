<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (SPEC section 6.5) — a real row, not a boolean on the
 * subscription.
 *
 * This shape is designed to survive the arrival of a real payment gateway:
 * when one is wired up, only PaymentService's internals change, not this
 * table and not its callers. `gateway` is "manual" for now and
 * `gateway_payment_id` stays null until a gateway fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            // Paise, matching plans.price_paise.
            $table->unsignedBigInteger('amount_paise');
            $table->char('currency', 3)->default('INR');

            $table->enum('mode', ['cash', 'online', 'free']);

            $table->string('gateway')->default('manual');
            $table->string('gateway_order_id')->nullable();
            $table->string('gateway_payment_id')->nullable();

            $table->enum('status', ['pending', 'captured', 'failed', 'refunded'])->default('pending');

            // Set when a salesman took the money in person.
            $table->foreignId('collected_by_salesman_id')->nullable()
                ->constrained('salesmen')->nullOnDelete();

            // Null until an admin reconciles the collection. Cash payments
            // with this still null are the reconciliation queue in
            // SPEC section 5.9, and the gate that moves a commission to paid.
            $table->timestamp('admin_verified_at')->nullable();

            $table->timestamps();

            // Drives the cash-collection reconciliation screen.
            $table->index(['mode', 'admin_verified_at']);

            $table->index(['collected_by_salesman_id', 'admin_verified_at']);
            $table->index('gateway_order_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
