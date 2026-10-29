<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer profile, 1:1 with a user carrying role = customer.
 *
 * Soft-deleted rather than removed on account deletion (SPEC section 4.10),
 * so leads and reviews keep their referent. See the Phase 5 / task 5.6 note
 * in PROGRESS.md for how the email is released for re-registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('phone', 20)->nullable();

            // Last known location, used to preselect the zone on next open
            // (SPEC section 4.2).
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Captured for expansion planning when the location matches no
            // defined zone — "We're not in your area yet".
            $table->string('pincode', 10)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('pincode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
