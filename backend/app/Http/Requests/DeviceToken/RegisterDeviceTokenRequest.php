<?php

namespace App\Http\Requests\DeviceToken;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/device-tokens (BUILD_PLAN 7.2) — called by any of the 3
 * Flutter apps on login/app start once the Flutter-side FCM SDK
 * integration exists (not built in this task — see the Before Launch
 * Checklist).
 */
class RegisterDeviceTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ];
    }
}
