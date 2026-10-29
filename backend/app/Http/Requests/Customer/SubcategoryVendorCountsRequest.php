<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Location for the subcategories screen's "X vendors" counts.
 *
 * Unlike VendorSearchRequest, a point/pincode is NOT required here: this
 * endpoint is called the moment the subcategories screen opens, and the
 * customer's location may not have resolved yet (SPEC section 4.2's own
 * fallback states). Missing location just means every count comes back
 * zero rather than the request being rejected — the grid still renders,
 * it simply has nothing to count against.
 */
class SubcategoryVendorCountsRequest extends FormRequest
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
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pincode' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function hasPoint(): bool
    {
        return $this->filled('latitude') && $this->filled('longitude');
    }
}
