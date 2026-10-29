<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a vendor draft from the salesman app (SPEC section 2.2).
 *
 * THE DUPLICATE CHECK SPANS TWO TABLES, and has to. There is no email column
 * on `vendors` — email is the login, so it lives on `users`. Checking only
 * `vendors` would let a salesman create a vendor against an address already
 * belonging to a customer, and the insert would then fail on the users unique
 * index with a raw integrity error instead of a clear message.
 *
 * Neither check is scoped withoutTrashed(): both underlying unique indexes
 * cover soft-deleted rows. A genuinely released address does not collide
 * anyway, since deletion tombstones it (see User::deleteWithTombstone()).
 *
 * Uniqueness is NOT enforced here as a hard failure — the controller checks it
 * again so it can return an existing draft for resume. These rules exist so
 * the app gets field-level errors for the ordinary case; see
 * VendorDraftController for why a duplicate is not always an error.
 */
class StoreVendorDraftRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],

            // Required: it is the number behind the customer Call button
            // (SPEC section 2.2).
            'phone' => ['required', 'string', 'max:20'],

            // Required: becomes the vendor's login.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],

            'address' => ['nullable', 'string', 'max:255'],

            // GPS captured in the field. Nullable because a salesman may be
            // indoors with no fix and can still save a draft.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'id_proof_type' => ['nullable', Rule::in(['aadhaar', 'pan'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'A phone number is required - customers call the vendor on it.',
            'email.required' => 'An email is required - it becomes the vendor login.',
        ];
    }
}
