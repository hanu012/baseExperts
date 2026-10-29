<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a distinct `rejected` outcome to the vendor lifecycle (task 4.3,
 * SPEC section 5.8's verification queue). Reverting a rejected
 * self-registered vendor to `draft` would erase both that a rejection ever
 * happened and that a subscription already exists against the account —
 * `rejected` is a terminal state of its own, paired with the already-present
 * `rejection_reason` column.
 *
 * See CLAUDE.md: widening an enum column needs a raw `ALTER TABLE ... MODIFY`
 * statement — Laravel's `->change()` isn't reliably supported for enum
 * columns via Doctrine DBAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE vendors MODIFY status ENUM(
            'draft', 'pending_payment', 'pending_verification', 'active', 'grace', 'expired', 'rejected'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE vendors MODIFY status ENUM(
            'draft', 'pending_payment', 'pending_verification', 'active', 'grace', 'expired'
        ) NOT NULL DEFAULT 'draft'");
    }
};
