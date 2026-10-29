<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor KYC documents (decided 2026-10-28; see App\Services\VendorKycService).
 *
 *  - document_types — the admin's list of documents (Aadhaar, PAN, Driving
 *    Licence…), each saying what it needs (number, expiry, back photo) and
 *    whether it is compulsory. A vendor account can be approved only once
 *    every compulsory, active type is Verified.
 *  - vendor_documents — one row per vendor per type. Files live on the
 *    private KYC disk (config filesystems.kyc_disk) and are only ever shown
 *    through short-lived signed URLs.
 *
 * The default types are not inserted here but by DocumentTypeSeeder, which
 * also moves the old single ID proof (vendors.id_proof_path) across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('has_number')->default(true);
            $table->string('number_label')->nullable();
            $table->string('number_hint', 100)->nullable();
            // Regex the number must match (without delimiters), e.g. PAN.
            $table->string('number_pattern')->nullable();
            // Collect and keep only the last 4 digits (Aadhaar — UIDAI rules).
            $table->boolean('mask_number')->default(false);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('has_back')->default(false);
            $table->boolean('is_compulsory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // Restrict: a type in use is deactivated, never deleted.
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'pending_upload', 'uploaded', 'in_verification', 'verified', 'reupload', 'expired',
            ])->default('pending_upload');
            $table->string('number', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('front_path')->nullable();
            $table->string('back_path')->nullable();
            $table->string('disk', 50)->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'document_type_id']);
            $table->index(['status', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
        Schema::dropIfExists('document_types');
    }
};
