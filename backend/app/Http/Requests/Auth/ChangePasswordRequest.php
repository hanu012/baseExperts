<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
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
            // Required even though the caller already holds a valid token.
            // Without it, a stolen or borrowed device is a full account
            // takeover: whoever holds the phone could lock the real owner out
            // by setting a password only they know.
            'current_password' => ['required', 'string'],

            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
