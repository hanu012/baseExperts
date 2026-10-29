<?php

namespace App\Models;

use App\Events\VendorDocumentUpdated;
use App\Models\Concerns\RecordsAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One vendor's copy of one DocumentType, with its own status. Rows are
 * created for every active type up front (VendorKycService::syncTypes), so
 * "Pending upload" is a real row the admin and the apps can list.
 *
 *   pending_upload → uploaded → in_verification → verified
 *                              ↘ reupload (admin's reason) → uploaded …
 *   verified → expired (daily, past expiry_date) → uploaded …
 *
 * Files are on the private KYC disk; only fileUrl()'s signed, short-lived
 * links ever leave the server.
 */
class VendorDocument extends Model
{
    use RecordsAuditLog;

    public const PENDING_UPLOAD = 'pending_upload';

    public const UPLOADED = 'uploaded';

    public const IN_VERIFICATION = 'in_verification';

    public const VERIFIED = 'verified';

    public const REUPLOAD = 'reupload';

    public const EXPIRED = 'expired';

    public const STATUSES = [
        self::PENDING_UPLOAD => 'Pending upload',
        self::UPLOADED => 'Uploaded',
        self::IN_VERIFICATION => 'In verification',
        self::VERIFIED => 'Verified',
        self::REUPLOAD => 'Re-upload needed',
        self::EXPIRED => 'Expired',
    ];

    /** Statuses in which the vendor or salesman may (re)upload. */
    public const UPLOADABLE = [self::PENDING_UPLOAD, self::UPLOADED, self::REUPLOAD, self::EXPIRED];

    /** How long a signed file link works. */
    private const URL_MINUTES = 30;

    protected $fillable = [
        'vendor_id',
        'document_type_id',
        'status',
        'number',
        'expiry_date',
        'front_path',
        'back_path',
        'disk',
        'review_note',
        'uploaded_by',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Every status change — from the admin's Documents tab, an upload, or
     * the daily expiry — goes live to the vendor's app (VendorDocumentUpdated
     * → `kyc.document`). After commit, so the app re-reads what was saved;
     * rescue(), so Reverb being down never fails the change itself.
     */
    protected static function booted(): void
    {
        static::saved(function (VendorDocument $document) {
            // A blank Pending-upload row (VendorKycService::syncTypes) is
            // bookkeeping, not news — only real status changes go out.
            $news = $document->wasRecentlyCreated
                ? $document->status !== self::PENDING_UPLOAD
                : $document->wasChanged('status');

            if (! $news) {
                return;
            }

            DB::afterCommit(fn () => rescue(function () use ($document) {
                $userId = $document->vendor?->user_id;

                if ($userId !== null) {
                    event(new VendorDocumentUpdated($userId, $document));
                }
            }, report: false));
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /** Whether the vendor or a salesman may upload over it (admins always may). */
    public function isUploadable(): bool
    {
        return in_array($this->status, self::UPLOADABLE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** The private disk KYC files go to. */
    public static function storageDisk(): string
    {
        return config('filesystems.kyc_disk', 'local');
    }

    /**
     * A signed link to the front or back file that stops working after
     * URL_MINUTES. Null when there is no file.
     */
    public function fileUrl(string $side = 'front'): ?string
    {
        $path = $side === 'back' ? $this->back_path : $this->front_path;

        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk($this->disk ?? self::storageDisk());

        // Only an ID proof copied over from before 2026-10-28 sits on the
        // public disk, which cannot sign links; it gets its plain URL.
        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(self::URL_MINUTES))
            : $disk->url($path);
    }
}
