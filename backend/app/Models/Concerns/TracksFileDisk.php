<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Stamps each record with the filesystem disk its file was written to.
 *
 * Set on the model rather than as a database default so the value follows
 * whatever disk the uploader actually used. A database default would freeze
 * today's disk name into the schema and quietly mislabel every row written
 * after the default changes — which is precisely the failure this column
 * exists to prevent.
 */
trait TracksFileDisk
{
    public static function bootTracksFileDisk(): void
    {
        static::creating(function ($model) {
            $model->disk ??= static::currentUploadDisk();
        });

        // A file added to an existing record on a later edit must record the
        // disk too, otherwise rows created before an upload keep a stale or
        // empty value.
        //
        // Checks EVERY path column, not just the primary one. A model with
        // two file fields (Vendor: shop photo + ID proof) would otherwise go
        // unstamped whenever only the secondary one was uploaded, leaving the
        // file invisible to the R2 migration — which selects on rows whose
        // disk is not yet the R2 disk.
        static::updating(function ($model) {
            if ($model->disk !== null) {
                return;
            }

            foreach ($model->fileDiskPathColumns() as $column) {
                if ($model->{$column} !== null) {
                    $model->disk = static::currentUploadDisk();

                    return;
                }
            }
        });
    }

    /**
     * The primary column holding the stored path this disk describes.
     *
     * Used as the default for fileUrl(). A model with several files names
     * them all in fileDiskPathColumns() instead.
     */
    public function fileDiskPathColumn(): string
    {
        return 'icon';
    }

    /**
     * Every column on this model holding a stored file path.
     *
     * Override this - not the boot hooks - on a model with more than one
     * file. Declaring the columns is enough; nothing else needs writing, and
     * a model that forgets is the bug this exists to prevent.
     *
     * @return array<int, string>
     */
    public function fileDiskPathColumns(): array
    {
        return [$this->fileDiskPathColumn()];
    }

    /**
     * Where uploads land right now.
     *
     * Filament's upload disk wins over filesystems.default: they differ in
     * this project (`public` vs `local`) and Filament is what writes the
     * files.
     */
    public static function currentUploadDisk(): string
    {
        return config('filament.default_filesystem_disk')
            ?? config('filesystems.default')
            ?? 'public';
    }

    /**
     * Public URL for the stored file, resolved through its own disk.
     *
     * Using the row's recorded disk rather than a hardcoded path is what lets
     * files on R2 and files still on the local disk coexist during the
     * migration — each row resolves against wherever it actually lives.
     */
    public function fileUrl(?string $path = null): ?string
    {
        $path ??= $this->{$this->fileDiskPathColumn()};

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk($this->disk ?? static::currentUploadDisk())->url($path);
    }
}
