<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real online payments through Razorpay Payment Links, behind the admin
 * setting `razorpay_payment_links_enabled` (off = the previous placeholder,
 * which records an online payment as captured on the spot).
 *
 * With it on, an online sale creates a payment link and waits:
 *  - the subscription is `pending_payment` (excluded from matching and
 *    quota like every non active/grace status) until Razorpay's webhook
 *    says paid;
 *  - an add-on is `pending_payment` and not counted in quota until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widening an enum needs a raw ALTER (CLAUDE.md) — ->change() is not
        // reliable on enum columns.
        DB::statement("ALTER TABLE subscriptions MODIFY status ENUM(
            'active', 'grace', 'expired', 'cancelled', 'superseded', 'pending_payment'
        ) NOT NULL DEFAULT 'active'");

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_link_url', 500)->nullable()->after('gateway_payment_id');
            $table->timestamp('payment_link_expires_at')->nullable()->after('payment_link_url');
            // Set when the payment is for an add-on rather than the
            // subscription itself, so the webhook knows what to release.
            $table->foreignId('subscription_addon_id')->nullable()->after('subscription_id')
                ->constrained('subscription_addons')->nullOnDelete();
            $table->index(['gateway', 'gateway_order_id']);
        });

        Schema::table('subscription_addons', function (Blueprint $table) {
            // paid | pending_payment | cancelled — existing rows were all
            // paid (cash or the placeholder), hence the default.
            $table->string('status', 20)->default('paid')->after('price_paise');
        });

        if (! DB::table('settings')->where('key', 'razorpay_payment_links_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'razorpay_payment_links_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'payments',
                'label' => 'Online payments via Razorpay',
                'description' => 'On: online sales send a Razorpay payment link and activate when paid. Off: online payments are recorded as paid immediately (placeholder).',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'razorpay_payment_links_enabled')->delete();

        Schema::table('subscription_addons', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'gateway_order_id']);
            $table->dropConstrainedForeignId('subscription_addon_id');
            $table->dropColumn(['payment_link_url', 'payment_link_expires_at']);
        });

        DB::statement("UPDATE subscriptions SET status = 'cancelled' WHERE status = 'pending_payment'");
        DB::statement("ALTER TABLE subscriptions MODIFY status ENUM(
            'active', 'grace', 'expired', 'cancelled', 'superseded'
        ) NOT NULL DEFAULT 'active'");
    }
};
