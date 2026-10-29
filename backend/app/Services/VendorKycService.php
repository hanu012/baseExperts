<?php

namespace App\Services;

use App\Exceptions\KycIncompleteException;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Vendor KYC documents (decided 2026-10-28).
 *
 * The admin lists the document types (Admin → Document Types) and ticks
 * which are compulsory. Every vendor gets one VendorDocument per active
 * type; each is uploaded from the admin panel, the salesman app (a vendor
 * they onboarded) or the vendor app, and verified or sent back by an admin.
 *
 * THE RULE: a vendor account is approved only when every compulsory, active
 * type is Verified — enforced in User::recordApprovalDecision() through
 * assertComplete(), whichever screen the approval comes from. That holds for
 * self-registered, salesman-sold and admin-created vendors alike.
 *
 * Aadhaar (mask_number): only the last 4 digits are ever collected or kept,
 * per UIDAI rules.
 */
class VendorKycService
{
    public function __construct(private readonly PushNotificationService $push) {}

    /** @return Collection<int, DocumentType> */
    public function activeTypes(): Collection
    {
        return DocumentType::query()->active()->ordered()->get();
    }

    /** Gives the vendor a Pending-upload row for every active type it lacks. */
    public function syncTypes(Vendor $vendor): void
    {
        $have = $vendor->documents()->pluck('document_type_id')->all();

        foreach ($this->activeTypes() as $type) {
            if (! in_array($type->id, $have, true)) {
                $vendor->documents()->firstOrCreate(
                    ['document_type_id' => $type->id],
                    ['status' => VendorDocument::PENDING_UPLOAD],
                );
            }
        }
    }

    /**
     * The vendor's documents for the active types, in the admin's order.
     *
     * @return Collection<int, VendorDocument>
     */
    public function documentsFor(Vendor $vendor): Collection
    {
        $this->syncTypes($vendor);

        return $vendor->documents()
            ->with('documentType')
            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
            ->get()
            ->sortBy(fn (VendorDocument $d) => [$d->documentType->sort_order, $d->documentType->name])
            ->values();
    }

    /**
     * Names of the compulsory, active types this vendor does not yet have
     * Verified (an expired one counts as missing).
     *
     * @return array<int, string>
     */
    public function missingCompulsory(Vendor $vendor): array
    {
        $verified = $vendor->documents()
            ->where('status', VendorDocument::VERIFIED)
            ->pluck('document_type_id')
            ->all();

        return DocumentType::query()
            ->active()
            ->where('is_compulsory', true)
            ->whereNotIn('id', $verified)
            ->ordered()
            ->pluck('name')
            ->all();
    }

    public function isComplete(Vendor $vendor): bool
    {
        return $this->missingCompulsory($vendor) === [];
    }

    /** @throws KycIncompleteException */
    public function assertComplete(Vendor $vendor): void
    {
        $missing = $this->missingCompulsory($vendor);

        if ($missing !== []) {
            throw new KycIncompleteException($missing);
        }
    }

    // ── Uploading ────────────────────────────────────────────────────────

    /** Saves an uploaded file to the private KYC disk. Returns its path. */
    public function storeFile(Vendor $vendor, UploadedFile $file): string
    {
        return $file->store('vendor-kyc/'.$vendor->id, VendorDocument::storageDisk());
    }

    /**
     * Records a new upload for this type: number, expiry and the stored
     * file paths. Status goes back to Uploaded (awaiting review) and any
     * earlier review is cleared; replaced files are deleted.
     *
     * Only an admin may upload over a document that is Verified or under
     * review ($byAdmin).
     *
     * @param  array{number?: ?string, expiry_date?: ?string, front_path: string, back_path?: ?string}  $data
     */
    public function recordUpload(Vendor $vendor, DocumentType $type, array $data, User $by, bool $byAdmin = false): VendorDocument
    {
        $document = $vendor->documents()->firstOrCreate(
            ['document_type_id' => $type->id],
            ['status' => VendorDocument::PENDING_UPLOAD],
        );

        if (! $byAdmin && ! $document->isUploadable()) {
            throw new RuntimeException('This document is already '.strtolower($document->statusLabel()).' and cannot be replaced.');
        }

        $oldFiles = array_filter([$document->front_path, $document->back_path]);
        $oldDisk = $document->disk;

        $document->fill([
            'status' => VendorDocument::UPLOADED,
            'number' => $type->has_number ? self::normaliseNumber($type, $data['number'] ?? null) : null,
            'expiry_date' => $type->has_expiry ? ($data['expiry_date'] ?? null) : null,
            'front_path' => $data['front_path'],
            'back_path' => $type->has_back ? ($data['back_path'] ?? null) : null,
            'disk' => VendorDocument::storageDisk(),
            'review_note' => null,
            'uploaded_by' => $by->getKey(),
            'uploaded_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        $stale = array_diff($oldFiles, array_filter([$document->front_path, $document->back_path]));

        if ($stale !== []) {
            rescue(fn () => Storage::disk($oldDisk ?? VendorDocument::storageDisk())->delete(array_values($stale)), report: false);
        }

        return $document;
    }

    /**
     * Validation rules for the number, by type. Shared by the API request
     * and the admin's upload form so the two cannot disagree.
     *
     * @return array<int, mixed>
     */
    public static function numberRules(DocumentType $type): array
    {
        if (! $type->has_number) {
            return ['nullable'];
        }

        if ($type->mask_number) {
            return ['required', 'string', 'digits:4'];
        }

        $rules = ['required', 'string', 'max:50'];

        if ($type->number_pattern) {
            $rules[] = 'regex:/^'.$type->number_pattern.'$/i';
        }

        return $rules;
    }

    /** "1234" → "XXXX XXXX 1234" for a masked type; otherwise trimmed upper-case. */
    public static function normaliseNumber(DocumentType $type, ?string $number): ?string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        return $type->mask_number
            ? 'XXXX XXXX '.substr(preg_replace('/\D/', '', $number), -4)
            : strtoupper($number);
    }

    // ── Reviewing (admin) ────────────────────────────────────────────────

    public function startReview(VendorDocument $document, User $admin): void
    {
        $document->update([
            'status' => VendorDocument::IN_VERIFICATION,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    public function verify(VendorDocument $document, User $admin): void
    {
        $document->update([
            'status' => VendorDocument::VERIFIED,
            'review_note' => null,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    /** Sends it back with a reason the vendor sees in the app (and as a push). */
    public function requestReupload(VendorDocument $document, User $admin, string $reason): void
    {
        $document->update([
            'status' => VendorDocument::REUPLOAD,
            'review_note' => $reason,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
        ]);

        rescue(fn () => $this->push->notifyDocumentReuploadRequested($document), report: false);
    }

    /**
     * Daily: documents past their expiry date become Expired, and the vendor
     * is told. Returns how many expired. An approved vendor stays approved —
     * the admin sees the expired document and decides what to do.
     */
    public function expireDue(): int
    {
        $due = VendorDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->whereIn('status', [VendorDocument::UPLOADED, VendorDocument::IN_VERIFICATION, VendorDocument::VERIFIED])
            ->with(['vendor.user', 'documentType'])
            ->get();

        foreach ($due as $document) {
            $document->update(['status' => VendorDocument::EXPIRED]);

            rescue(fn () => $this->push->notifyDocumentExpired($document), report: false);
        }

        return $due->count();
    }
}
