<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FCM registration tokens (BUILD_PLAN 7.2), one row per device a user
 * has ever registered — a user can be signed in on more than one
 * device, so this is a table, not a column on `users`.
 *
 * `token` is unique across the WHOLE table, not scoped per user: an
 * FCM token identifies one physical app install, and re-registering
 * the same token (app reopened, silent refresh) must update that
 * existing row in place, even under a different user_id if the
 * device was reassigned/re-logged-in — never create a duplicate row
 * for a token that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('token')->unique();
            $table->enum('platform', ['android', 'ios']);

            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
