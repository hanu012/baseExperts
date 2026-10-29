<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notification campaigns and automated triggers (SPEC section 5.12).
 *
 * One row per dispatch — either composed by an admin against an audience
 * filter, or raised by a trigger (expiry reminders at T-15/T-7/T-1,
 * verification approved, review request, lead received).
 *
 * NOTE: this is a campaign log, not Laravel's own per-user notifications
 * table. If an in-app inbox with read state is needed later, publish
 * Laravel's notifications migration under a different table name — the two
 * would otherwise collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('body');

            // Null targets every app.
            $table->enum('target_app', ['salesman', 'vendor', 'customer'])->nullable();

            // 'manual' for the composer; otherwise the trigger name, e.g.
            // subscription_expiring, verification_approved, review_request.
            $table->string('type')->default('manual');

            // Audience filter as composed by the admin — role, zone, plan,
            // expiry window. JSON because the filters differ per campaign and
            // are never queried on individually.
            $table->json('audience')->nullable();

            // Deep link target opened when the push is tapped.
            $table->string('link_url')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The scheduler picks up due, unsent campaigns.
            $table->index(['scheduled_at', 'sent_at']);
            $table->index(['target_app', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
