<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reporting the customer's location (SPEC section 4.2, task 4.6) — either
 * a GPS point or a pincode fallback when GPS is denied or unavailable.
 * Not neither; both is fine (the point takes precedence when present).
 */
class UpdateCustomerLocationRequest extends FormRequest
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasPoint = $this->filled('latitude') && $this->filled('longitude');
            $hasPincode = $this->filled('pincode');

            if (! $hasPoint && ! $hasPincode) {
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
