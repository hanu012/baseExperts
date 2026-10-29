<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salesman commissions (SPEC section 10).
 *
 * Generated only on paid subscriptions sold by a salesman (source =
 * salesman) — never on free trials or vendor self-service purchases. Starts
 * pending and moves to paid once an admin has verified the underlying
 * payment was reconciled, which matters most for cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();

            // Unique: one commission per sale. This is the second line of
            // defence behind subscriptions.idempotency_key — a retried
            // request cannot pay a salesman twice.
            $table->foreignId('subscription_id')->unique()
                ->constrained('subscriptions')->cascadeOnDelete();

            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();

            $table->unsignedBigInteger('amount_paise');

            // The rate used at the time, in basis points, copied from the
            // salesman or plan so later rate changes do not rewrite history.
            $table->unsignedInteger('rate_bps');

            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference')->nullable();

            $table->timestamps();

            // Earnings tab: pending vs paid for one salesman.
            $table->index(['salesman_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
