<?php

namespace App\Http\Requests\Salesman;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a salesman may change about their own record (SPEC section 2.5).
 *
 * Name and phone only — see SalesmanController::updateMe() for why email
 * and the organisational fields are not here. Both are `sometimes`, so the
 * profile screen can PATCH one field without having to resend the other.
 */
class UpdateSalesmanProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already restricts this to role:salesman, and the
        // controller only ever touches $request->user()->salesman — there
        // is no id to tamper with.
        return $this->user()?->salesman !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                // Scoped to the salesmen table and ignoring this row: the
                // unique index is on salesmen.phone, so re-saving an
                // unchanged number must not be rejected as a duplicate of
                // itself.
                Rule::unique('salesmen', 'phone')->ignore($this->user()->salesman->id),
            ],
        ];
    }
}
