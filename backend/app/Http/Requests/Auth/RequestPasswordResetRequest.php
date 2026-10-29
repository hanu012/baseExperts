<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/auth/password-reset-requests.
 *
 * An email with no account is refused with a field error, so the user can
 * see they typed the wrong address instead of waiting for a mail that
 * never comes. That does reveal whether an email is registered; the
 * `password-reset-request` rate limit (5 an hour per IP) is what keeps
 * this from being used to test addresses in bulk.
 */
class RequestPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // A deleted (tombstoned) account does not count.
                Rule::exists('users', 'email')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'No account found with this email. Check the address and try again.',
        ];
    }
}
