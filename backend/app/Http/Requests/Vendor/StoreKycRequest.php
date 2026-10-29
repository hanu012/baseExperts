<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * KYC upload for a vendor draft (SPEC section 2.2): shop photo and owner
 * Aadhaar/PAN.
 *
 * Both files are optional per request so the app can upload them one at a
 * time — a salesman on a weak connection should be able to land the shop
 * photo without the ID proof retrying alongside it.
 */
class StoreKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Images only, and capped: these are photographed in the field on
            // a phone, so a modern camera file can easily exceed a default
            // upload limit.
            'shop_photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'id_proof' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'id_proof_type' => ['nullable', Rule::in(['aadhaar', 'pan'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->hasFile('shop_photo') && ! $this->hasFile('id_proof') && ! $this->filled('id_proof_type')) {
                $validator->errors()->add('shop_photo', 'Upload at least one document.');
            }

            // An ID proof without its type is unusable downstream: the
            // verification queue has to show reviewers which document they
            // are looking at.
            if ($this->hasFile('id_proof') && ! $this->filled('id_proof_type')) {
                $validator->errors()->add('id_proof_type', 'Say whether this is an Aadhaar or a PAN.');
            }
        });
    }
}
