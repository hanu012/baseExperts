<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/vendors/{vendor}/detail (SPEC section 4 item 6, task 5.4).
 * Location is an explicit optional param, same "never self-resolved"
 * reasoning as VendorSearchRequest — but unlike search, a missing point
 * here just means the response omits distance, not a validation error.
 */
class VendorDetailRequest extends FormRequest
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
        ];
    }

    public function hasPoint(): bool
    {
        return $this->filled('latitude') && $this->filled('longitude');
    }
}
