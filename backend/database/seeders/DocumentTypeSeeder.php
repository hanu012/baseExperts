<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Database\Seeder;

/**
 * The starting KYC document types (VendorKycService), and a one-time move
 * of the old single ID proof into them. Safe to run again:
 *
 *   php artisan db:seed --class=DocumentTypeSeeder
 *
 * Types are only CREATED here, never updated — once they exist, Admin →
 * Document Types owns them (an admin's "compulsory" choice is not reset).
 *
 * Aadhaar and PAN start compulsory; change that in the admin.
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->types() as $order => $type) {
            DocumentType::query()->firstOrCreate(['code' => $type['code']], $type + ['sort_order' => $order + 1]);
        }

        $this->importLegacyIdProofs();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function types(): array
    {
        return [
            [
                'code' => 'aadhaar',
                'name' => 'Aadhaar Card',
                'description' => 'Front and back. Please mask the first 8 digits of the number.',
                'has_number' => true,
                'number_label' => 'Last 4 digits of Aadhaar',
                'number_hint' => '1234',
                'mask_number' => true,
                'has_expiry' => false,
                'has_back' => true,
                'is_compulsory' => true,
            ],
            [
                'code' => 'pan',
                'name' => 'PAN Card',
                'description' => 'Front of the card.',
                'has_number' => true,
                'number_label' => 'PAN number',
                'number_hint' => 'ABCDE1234F',
                'number_pattern' => '[A-Z]{5}[0-9]{4}[A-Z]',
                'has_expiry' => false,
                'has_back' => false,
                'is_compulsory' => true,
            ],
            [
                'code' => 'driving_license',
                'name' => 'Driving Licence',
                'description' => 'Front and back.',
                'has_number' => true,
                'number_label' => 'Licence number',
                'number_hint' => 'GJ01 20200012345',
                'has_expiry' => true,
                'has_back' => true,
                'is_compulsory' => false,
            ],
            [
                'code' => 'passport',
                'name' => 'Passport',
                'description' => 'Photo page and address page.',
                'has_number' => true,
                'number_label' => 'Passport number',
                'number_hint' => 'A1234567',
                'number_pattern' => '[A-Z][0-9]{7}',
                'has_expiry' => true,
                'has_back' => true,
                'is_compulsory' => false,
            ],
            [
                'code' => 'voter_id',
                'name' => 'Voter ID',
                'description' => 'Front and back.',
                'has_number' => true,
                'number_label' => 'EPIC number',
                'number_hint' => 'ABC1234567',
                'number_pattern' => '[A-Z]{3}[0-9]{7}',
                'has_expiry' => false,
                'has_back' => true,
                'is_compulsory' => false,
            ],
            [
                'code' => 'gst',
                'name' => 'GST Certificate',
                'description' => 'Registration certificate, if the business is GST-registered.',
                'has_number' => true,
                'number_label' => 'GSTIN',
                'number_hint' => '24ABCDE1234F1Z5',
                'number_pattern' => '[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]',
                'has_expiry' => false,
                'has_back' => false,
                'is_compulsory' => false,
            ],
        ];
    }

    /**
     * Before 2026-10-28 a vendor had one ID proof photo (vendors.id_proof_path,
     * Aadhaar or PAN). It becomes that type's document, Uploaded (not
     * Verified — nobody checked it against a number), on the file's existing
     * disk. Vendors that already have that document are left alone.
     */
    private function importLegacyIdProofs(): void
    {
        $types = DocumentType::query()->whereIn('code', ['aadhaar', 'pan'])->pluck('id', 'code');

        Vendor::query()
            ->whereNotNull('id_proof_path')
            ->whereIn('id_proof_type', ['aadhaar', 'pan'])
            ->each(function (Vendor $vendor) use ($types) {
                $typeId = $types[$vendor->id_proof_type] ?? null;

                if ($typeId === null || $vendor->documents()->where('document_type_id', $typeId)->whereNotNull('front_path')->exists()) {
                    return;
                }

                $vendor->documents()->updateOrCreate(['document_type_id' => $typeId], [
                    'status' => VendorDocument::UPLOADED,
                    'front_path' => $vendor->id_proof_path,
                    'disk' => $vendor->disk,
                    'uploaded_at' => $vendor->updated_at,
                    'review_note' => null,
                ]);
            });
    }
}
