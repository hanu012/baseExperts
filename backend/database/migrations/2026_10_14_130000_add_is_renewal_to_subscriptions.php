<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a subscription bought as a renewal (POST /subscriptions/renew) —
 * as opposed to a first subscription or a plan change, which also link a
 * previous_subscription_id but date and price differently. A renewal
 * carries the days left on the plan it replaces; completing a held online
 * renewal (Razorpay) needs to know that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('is_renewal')->default(false)->after('previous_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_renewal');
        });
    }
};
