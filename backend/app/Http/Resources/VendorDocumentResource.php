<?php

namespace App\Http\Resources;

use App\Models\VendorDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One KYC document for the apps. File links are signed and short-lived
 * (VendorDocument::fileUrl) — the app re-fetches the list rather than
 * keeping them.
 *
 * @mixin VendorDocument
 */
class VendorDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->documentType;

        return [
            // Null until something is uploaded, so the app keys rows by type.
            'id' => $this->status === VendorDocument::PENDING_UPLOAD ? null : $this->id,
            'document_type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'description' => $type->description,
                'has_number' => $type->has_number,
                'number_label' => $type->number_label,
                'number_hint' => $type->number_hint,
                'mask_number' => $type->mask_number,
                'has_expiry' => $type->has_expiry,
                'has_back' => $type->has_back,
                'is_compulsory' => $type->is_compulsory,
            ],
            'status' => $this->status,
            'number' => $this->number,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'front_url' => $this->fileUrl('front'),
            'back_url' => $this->fileUrl('back'),
            'review_note' => $this->status === VendorDocument::REUPLOAD ? $this->review_note : null,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'can_upload' => $this->isUploadable(),
        ];
    }
}
