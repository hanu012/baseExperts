<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `'s3'` database default from media.disk and makes the column
 * nullable, bringing it in line with the disk columns on categories,
 * subcategories, vendors and banners.
 *
 * WHY: the default was aspirational. Nothing is on S3 or Cloudflare R2 —
 * uploads land on the local `public` disk because no R2 credentials exist
 * yet — so the first Phase 4 portfolio upload would have been stamped `s3`
 * the moment it was written, recording a location the file was never in.
 * That is exactly the mislabelling the per-row `disk` column was introduced
 * to prevent, so leaving the default in place would have defeated it at the
 * one table that had the column first.
 *
 * No default replaces it. The value is stamped by the model at write time
 * (see App\Models\Concerns\TracksFileDisk) so it follows whatever disk the
 * uploader actually used, rather than freezing one disk name into the schema
 * where it goes stale the moment the configuration changes.
 *
 * Media is deliberately NOT given the trait here — Phase 4 builds the upload
 * logic and wires it up then. Until then the column is simply honest about
 * knowing nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('disk')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('disk')->nullable(false)->default('s3')->change();
        });
    }
};
