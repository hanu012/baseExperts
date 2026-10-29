<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/user/profile — the personal details every app's profile can
 * edit: name, email and phone. Every field is optional; send only what
 * changed.
 *
 * Changing the EMAIL needs the current password: it is both the sign-in
 * name and where a password-reset link goes, so whoever holds an unlocked
 * phone must not be able to point it at their own inbox.
 *
 * The phone lives on the role's own table, so its rules follow the role:
 * a vendor's and a salesman's are required and unique (each table has a
 * unique index); a customer's is optional.
 */
class UpdateProfileRequest extends FormRequest
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
        $user = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'current_password' => ['nullable', 'string'],
            'phone' => $this->phoneRules(),
        ];
    }

    /** @return array<int, mixed> */
    private function phoneRules(): array
    {
        $user = $this->user();
        $format = 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/';

        return match ($user->role) {
            UserRole::Vendor => [
                'sometimes', 'required', 'string', 'max:20', $format,
                // Trashed rows still hold the unique index (see
                // UpdateVendorMeRequest).
                Rule::unique('vendors', 'phone')->ignore($user->vendor?->getKey())->withoutTrashed(),
            ],
            UserRole::Salesman => [
                'sometimes', 'required', 'string', 'max:20', $format,
                Rule::unique('salesmen', 'phone')->ignore($user->salesman?->getKey()),
            ],
            default => ['sometimes', 'nullable', 'string', 'max:20', $format],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->emailIsChanging()) {
                return;
            }

            $password = (string) $this->input('current_password', '');

            if ($password === '' || ! Hash::check($password, $this->user()->password)) {
                $validator->errors()->add(
                    'current_password',
                    'Enter your current password to change your email.'
                );
            }
        });
    }

    public function emailIsChanging(): bool
    {
        return $this->filled('email')
            && strcasecmp(trim((string) $this->input('email')), (string) $this->user()->email) !== 0;
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'phone.unique' => 'This phone number is already used by another account.',
            'email.unique' => 'This email is already used by another account.',
        ];
    }
}
