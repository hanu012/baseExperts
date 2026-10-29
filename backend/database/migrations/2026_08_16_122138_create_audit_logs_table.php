<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, when (SPEC section 5.14).
 *
 * Called out in SPEC as especially important because salesmen can grant free
 * subscriptions — so the free-trial path, the cash reconciliation path and
 * every admin override should all write here.
 *
 * No updated_at: an audit row is written once and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Null for system-generated changes such as the nightly expiry job.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // created, updated, deleted, approved, rejected, granted_free_trial...
            $table->string('action');

            $table->morphs('auditable');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
