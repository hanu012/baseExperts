<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead is written when a customer taps Call or WhatsApp — *before* the
 * dialer or WhatsApp intent opens (SPEC section 4.7).
 *
 * This ordering is the point of the table. The lead record is what makes
 * reviews provable, renewal evidence real, and analytics possible; if it were
 * written afterwards, every abandoned tap would vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // Restrict: leads are the evidence trail behind reviews and
            // renewals, so master data they point at must not disappear.
            $table->foreignId('subcategory_id')->constrained('subcategories')->restrictOnDelete();

            // Null when the customer matched by pincode rather than polygon.
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();

            $table->enum('channel', ['call', 'whatsapp']);

            $table->timestamps();

            // Vendor Leads tab, and "leads received this month" on the
            // salesman's My Vendors tab.
            $table->index(['vendor_id', 'created_at']);

            // SPEC section 9: a review is permitted only if a lead exists
            // between this customer and vendor within the last 30 days. This
            // index is what makes that check cheap on every review attempt.
            $table->index(['customer_id', 'vendor_id', 'created_at']);

            // Leads & call analytics, filterable by date range.
            $table->index(['subcategory_id', 'created_at']);
            $table->index(['zone_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
