<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded files — vendor portfolio photos and videos in practice
 * (SPEC section 3.5), routed through the admin moderation queue in
 * section 5.10 before going live.
 *
 * Polymorphic so KYC documents and banner assets can share it later without
 * another table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->morphs('mediable');

            // Portfolio is organised under a subcategory ("photos of completed
            // work" per service). Null for media that is not portfolio.
            $table->foreignId('subcategory_id')->nullable()
                ->constrained('subcategories')->nullOnDelete();

            $table->string('disk')->default('s3');
            $table->string('path');
            $table->enum('type', ['image', 'video']);
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Nothing is visible to customers until an admin approves it.
            $table->enum('moderation_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // The moderation queue, oldest first.
            $table->index(['moderation_status', 'created_at']);

            // Counting a vendor's photos and videos against the plan quota.
            $table->index(['mediable_type', 'mediable_id', 'type', 'moderation_status'], 'media_quota_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
