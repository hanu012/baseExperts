<?php

namespace App\Http\Requests\Vendor;

use App\Models\DocumentType;
use App\Services\VendorKycService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One KYC document upload (VendorKycService), from the vendor app or the
 * salesman app. What else is required depends on the document type: the
 * number (only the last 4 digits for Aadhaar), an expiry date in the
 * future, and the back photo.
 */
class StoreVendorDocumentRequest extends FormRequest
{
    private const FILE = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

    public function authorize(): bool
    {
        return true;
    }

    public function documentType(): ?DocumentType
    {
        return DocumentType::query()->active()->find($this->integer('document_type_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->documentType();

        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'number' => $type ? VendorKycService::numberRules($type) : ['nullable'],
            'expiry_date' => $type?->has_expiry
                ? ['required', 'date_format:Y-m-d', 'after:today']
                : ['nullable'],
            'front' => ['required', ...self::FILE],
            'back' => $type?->has_back ? ['required', ...self::FILE] : ['nullable', ...self::FILE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.digits' => 'Enter only the last 4 digits.',
            'number.regex' => 'This number is not in the right format.',
            'expiry_date.after' => 'This document has expired. Upload a current one.',
            'front.max' => 'The file must be 10 MB or smaller.',
            'back.max' => 'The file must be 10 MB or smaller.',
        ];
    }
}
