<?php

namespace App\Http\Requests\Vendor;

use App\Services\VendorSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The customer vendor-search query (SPEC section 4 item 4, task 5.3).
 * Location is always explicit — a GPS point or a pincode, same either/or
 * shape as UpdateCustomerLocationRequest — never resolved from the
 * caller's stored profile, since this endpoint is public and returns
 * other people's data, not the caller's own record.
 */
class VendorSearchRequest extends FormRequest
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
            'subcategory_id' => ['required', 'integer', 'exists:subcategories,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            // The three list-screen sort chips (task 5.3's redesign).
            // Defaults to "nearest" in the service itself, not here, so
            // every other caller of VendorSearchService::search() keeps
            // its own default rather than silently depending on this
            // request class's.
            'sort' => ['sometimes', Rule::in([
                VendorSearchService::SORT_NEAREST,
                VendorSearchService::SORT_RATING,
                VendorSearchService::SORT_NEW,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasPoint() && ! $this->filled('pincode')) {
                $validator->errors()->add(
                    'pincode',
                    'Provide either a latitude/longitude pair or a pincode.'
                );
            }
        });
    }

    public function hasPoint(): bool
    {
        return $this->filled('latitude') && $this->filled('longitude');
    }
}
