<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor profile, 1:1 with a user carrying role = vendor.
 *
 * Lifecycle (SPEC section 7):
 *   draft -> pending_payment -> pending_verification -> active -> grace -> expired
 *
 * Salesman-added vendors skip pending_verification and go straight to active,
 * because the salesman met them in person (SPEC section 2.2). `is_suspended`
 * is a separate admin flag for policy violations, independent of subscription
 * dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('business_name');
            $table->string('owner_name');

            // Unique because the salesman flow rejects a duplicate phone
            // before anything else is saved (SPEC section 2.2). It is also
            // the number behind the customer Call button.
            $table->string('phone', 20)->unique();

            $table->string('address')->nullable();

            // Shop location. Decimal rather than a POINT: this is read far
            // more than it is matched against, and ST_Distance_Sphere accepts
            // a constructed point just as well.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->enum('status', [
                'draft',
                'pending_payment',
                'pending_verification',
                'active',
                'grace',
                'expired',
            ])->default('draft');

            $table->boolean('is_suspended')->default(false);

            // KYC (SPEC section 2.2). Paths only; files live on the S3/R2 disk.
            $table->string('shop_photo_path')->nullable();
            $table->enum('id_proof_type', ['aadhaar', 'pan'])->nullable();
            $table->string('id_proof_path')->nullable();

            // Null when the vendor self-registered.
            $table->foreignId('created_by_salesman_id')->nullable()
                ->constrained('salesmen')->nullOnDelete();

            // Verification queue (SPEC section 5.8).
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            // Denormalised review aggregates so search does not recompute them
            // per row. SPEC section 4.5 sorts on rating with a minimum review
            // count, so both numbers are needed together.
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Customer search filters on these before anything else.
            $table->index(['status', 'is_suspended']);
            $table->index(['rating_avg', 'rating_count']);
            $table->index(['latitude', 'longitude']);
            $table->index('created_by_salesman_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
