<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer report of a vendor (SPEC section 4 item 10 / section 5.15) —
 * deliberately minimal. No status/assignment/resolution fields: those
 * belong to the full Support Tickets module (SPEC section 5.15), planned
 * for Phase 6. This table exists only so a report isn't silently dropped
 * in the meantime.
 *
 * The unique pair means "one report ever" per customer-vendor, not "one
 * open report" — there is no status column yet to tell a resolved report
 * from a pending one. See PROGRESS.md's Before Launch Checklist: Phase 6
 * needs to revisit this constraint once resolution status exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->text('reason');

            $table->timestamps();

            $table->unique(['customer_id', 'vendor_id']);
            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
