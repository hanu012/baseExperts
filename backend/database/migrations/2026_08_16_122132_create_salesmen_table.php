<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salesman profile, 1:1 with a user carrying role = salesman.
 *
 * SPEC section 1: salesmen never self-register — an admin creates the account
 * and a temp password is shown once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('employee_code')->unique();
            $table->string('phone', 20)->unique();

            // Monthly target in paise, shown against achieved on the Earnings
            // tab (SPEC section 2.4).
            $table->unsignedBigInteger('monthly_target_paise')->default(0);

            // Default commission rate in basis points (1200 = 12.00%). Stored
            // as an integer for the same reason money is: no float drift.
            // The rate actually used is copied onto each commission row.
            $table->unsignedInteger('commission_rate_bps')->default(0);

            // Forced password change on first login (SPEC section 2.1).
            $table->boolean('must_change_password')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesmen');
    }
};
