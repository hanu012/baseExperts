<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews (SPEC section 9).
 *
 * A review requires a matching lead between that customer and vendor, created
 * within the last 30 days. Without that rule reviews can be fabricated with
 * no proof of contact — so the review hangs off the lead, not off the vendor.
 *
 * The 24-hour edit window is `created_at + 24 hours`, enforced in the policy
 * rather than stored: a column would only be able to drift from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // Unique enforces "one review per lead" in the database, not just
            // in application code.
            $table->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();

            // Denormalised from the lead so vendor and customer listings do
            // not have to join through it.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // Admin can hide, rather than delete (SPEC section 5.6).
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hidden_reason')->nullable();

            // Vendor's right of reply.
            $table->text('vendor_reply')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            // Vendor detail page: visible reviews, newest first.
            $table->index(['vendor_id', 'is_hidden', 'created_at']);

            $table->index(['customer_id', 'created_at']);
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
