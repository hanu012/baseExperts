<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which filesystem disk each stored file actually lives on, matching
 * the `disk` column `media` already carries.
 *
 * WHY: uploads currently land on the local `public` disk because no
 * Cloudflare R2 credentials exist yet. Without a per-row disk, the eventual
 * move to R2 is all-or-nothing — flipping the config would instantly
 * mislabel every file already written, and a half-finished migration would
 * be unrecoverable because nothing records which rows had moved. With this
 * column the migration becomes row-at-a-time and re-runnable.
 *
 * Nullable rather than defaulted: a null means "written before this column
 * existed", which the backfill below resolves for the two tables that can
 * already hold files. New writes get the disk set by the model
 * (TracksFileDisk) rather than by a database default, so the value follows
 * whatever disk the uploader actually used instead of a constant that goes
 * stale the moment the default changes.
 */
return new class extends Migration
{
    /**
     * Tables owning file paths, and the column each disk value describes.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'categories' => 'icon',
        'subcategories' => 'icon',
        'vendors' => 'shop_photo_path',
        'banners' => 'image_path',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('disk')->nullable()->after('id');
            });
        }

        // Backfill only what can already hold a file. Categories and
        // subcategories are the sole tables with uploads today (Phase 1);
        // vendors and banners are written in Phases 3 and 6, by which time
        // the model default applies on create.
        //
        // The value is Filament's upload disk, NOT filesystems.default. Those
        // differ here — filesystems.default is `local`, while Filament writes
        // to `public`. Backfilling `local` would point every row at a
        // directory the files were never written to.
        $disk = self::currentUploadDisk();

        foreach (['categories', 'subcategories'] as $table) {
            DB::table($table)->whereNull('disk')->update(['disk' => $disk]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('disk');
            });
        }
    }

    private static function currentUploadDisk(): string
    {
        return config('filament.default_filesystem_disk')
            ?? config('filesystems.default')
            ?? 'public';
    }
};
