<?php

namespace App\Http\Requests\DeviceToken;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/device-tokens (BUILD_PLAN 7.2) — called on logout so a
 * signed-out device stops receiving push, rather than relying on FCM
 * to notice the token has gone stale.
 */
class UnregisterDeviceTokenRequest extends FormRequest
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
        ];
    }
}
