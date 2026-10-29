<?php

namespace App\Http\Requests\Vendor;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A vendor editing their own business profile (SPEC section 3.2).
 *
 * Deliberately narrow. The vendor may change how their business is
 * described and how customers reach them — nothing that decides what they
 * are entitled to or whether they are trusted. `status`,
 * `is_suspended`, the KYC paths and `verified_*` are all absent by
 * decision, not by omission: those belong to the admin, and a request
 * that could set them would let a vendor approve themselves.
 *
 * There is no `email` field either. The address is the sign-in identity,
 * sits on `users` under a unique index, and changing it is an auth
 * operation rather than a profile edit.
 */
class UpdateVendorMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already restricts this to the vendor role; the
        // controller resolves the vendor from the token, never from input,
        // so there is no other vendor this request could reach.
        return $this->user()?->vendor !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $vendorId = $this->user()->vendor->id;

        return [
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],

            // Same uniqueness the salesman add-vendor flow enforces (SPEC
            // section 2.2). Trashed rows count: the index does not exempt
            // them, so ignoring them here would produce a save that
            // passes validation and then fails on insert.
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('vendors', 'phone')->ignore($vendorId)->withoutTrashed(),
            ],

            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'about' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number already belongs to another vendor.',
        ];
    }

    /**
     * Only the keys above, so a crafted extra field cannot ride along
     * into the update.
     *
     * @return array<string, mixed>
     */
    public function updates(): array
    {
        return $this->safe()->only([
            'business_name',
            'owner_name',
            'phone',
            'address',
            'city',
            'about',
        ]);
    }

    /**
     * @return class-string
     */
    public function model(): string
    {
        return Vendor::class;
    }
}
