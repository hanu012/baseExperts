<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expiry-reminder idempotency (BUILD_PLAN 7.2, T-15/T-7/T-1) — same
 * "nullable timestamp on the owning row, set once, checked before
 * acting again" idiom `leads.review_requested_at` already established
 * (task 4.8), not a row in the `notifications` dispatch-log table
 * (querying that table's JSON `audience` column for "was T-15 already
 * sent for subscription 42" would work but isn't how this codebase
 * tracks once-ever state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('reminder_sent_t15_at')->nullable()->after('end_date');
            $table->timestamp('reminder_sent_t7_at')->nullable()->after('reminder_sent_t15_at');
            $table->timestamp('reminder_sent_t1_at')->nullable()->after('reminder_sent_t7_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_t15_at', 'reminder_sent_t7_at', 'reminder_sent_t1_at']);
        });
    }
};
