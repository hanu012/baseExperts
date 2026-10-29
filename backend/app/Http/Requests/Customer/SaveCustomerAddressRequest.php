<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a saved address. Edit sends the whole form again, so both use
 * the same rules.
 */
class SaveCustomerAddressRequest extends FormRequest
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
            'label' => ['required', 'string', Rule::in(CustomerAddress::LABELS)],
            // Letters and spaces, two words at most — "Mom's place" style
            // names belong in the building/house fields, not the label.
            'other_label' => [
                'nullable',
                'required_if:label,other',
                'string',
                'min:2',
                'max:40',
                'regex:/^\p{L}+(?: \p{L}+)?$/u',
            ],
            // Optional: a villa may have no building, and a pin on the map
            // is enough to find the customer.
            'apartment_number' => ['nullable', 'string', 'max:120'],
            'building_name' => ['nullable', 'string', 'max:120'],
            'street_address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'other_label.required_if' => 'Give this address a name.',
            'other_label.regex' => 'Use letters only, at most two words.',
        ];
    }
}
