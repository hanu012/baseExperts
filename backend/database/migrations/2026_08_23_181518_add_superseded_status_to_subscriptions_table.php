<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a distinct `superseded` outcome to the subscription lifecycle
 * (task 4.7, SPEC section 3 item 6 — upgrade/downgrade). A plan change
 * replaces the old subscription with a new one
 * (`previous_subscription_id`); the old row needs a status that isn't
 * `cancelled`, so admin reporting can tell an upsell apart from actual
 * churn.
 *
 * See CLAUDE.md: widening an enum column needs a raw `ALTER TABLE ...
 * MODIFY` statement — Laravel's `->change()` isn't reliably supported
 * for enum columns via Doctrine DBAL. Same shape as vendors.status
 * gaining `rejected` in task 4.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY status ENUM(
            'active', 'grace', 'expired', 'cancelled', 'superseded'
        ) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY status ENUM(
            'active', 'grace', 'expired', 'cancelled'
        ) NOT NULL DEFAULT 'active'");
    }
};
