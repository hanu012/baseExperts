<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
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
            // Required for the same reason ChangePasswordRequest requires
            // current_password: without it, a stolen or borrowed device
            // could delete the real owner's account.
            'password' => ['required', 'string'],
        ];
    }
}
