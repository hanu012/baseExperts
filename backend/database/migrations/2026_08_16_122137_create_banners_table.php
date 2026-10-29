<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app banners (SPEC section 5.5), targeted per flavour with a scheduled
 * window and click tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            // Matches the three Flutter flavours.
            $table->enum('target_app', ['salesman', 'vendor', 'customer']);

            $table->string('title')->nullable();

            // Placement slot within the target app, e.g. home_top.
            $table->string('position')->default('home_top');

            $table->string('image_path');
            $table->string('link_url')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('click_count')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Resolving which banners to show right now.
            $table->index(['target_app', 'position', 'is_active', 'starts_at', 'ends_at'], 'banners_serving_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
