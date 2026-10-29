<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Portfolio uploads are now auto-approved (VendorPortfolioController), so
 * anything still sitting in the old review queue is released too —
 * otherwise those files would stay invisible to customers forever, with
 * no queue left to approve them from.
 *
 * Rejected media is left alone: an admin deliberately took it down.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->where('moderation_status', 'pending')
            ->update([
                'moderation_status' => 'approved',
                'moderated_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Not reversible: which rows were pending before is not recorded,
        // and re-hiding approved work from customers is not a safe guess.
    }
};
