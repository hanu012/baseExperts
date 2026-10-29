<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin approval becomes the single gate on an account (SPEC section 3.1),
 * replacing email verification.
 *
 * Previously the only gate was `email_verified_at`, it applied to vendors
 * alone, and it was enforced by refusing a token at login. That had two
 * problems: a self-registered salesman or customer walked straight in
 * unvetted, and a vendor waiting on verification could not sign in at all,
 * so the app had nowhere to show them *why* they were stuck.
 *
 * `email_verified_at` is left in place rather than dropped — it still
 * records the admin-created accounts that were stamped verified on
 * creation, and dropping a column is not reversible from the data side if
 * this gets rolled back.
 *
 * BACKFILL: every row that exists when this runs is set to 'approved'.
 * Defaulting them to 'pending' would lock out every live account —
 * including the only admin — the moment the middleware ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->after('role');

            $table->timestamp('approval_decided_at')->nullable()->after('approval_status');

            // Who to ask about a rejection, and why. Nullable because an
            // approval needs no explanation and the seeded admin has nobody
            // above it to have decided.
            $table->foreignId('approval_decided_by')
                ->nullable()
                ->after('approval_decided_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('approval_note')->nullable()->after('approval_decided_by');

            // The app's pending screen and the admin's review queue both
            // filter on this column on every request.
            $table->index('approval_status');
        });

        DB::table('users')->update([
            'approval_status' => 'approved',
            'approval_decided_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approval_decided_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn([
                'approval_status',
                'approval_decided_at',
                'approval_decided_by',
                'approval_note',
            ]);
        });
    }
};
