<?php

namespace App\Http\Requests\User;

use App\Support\Languages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Restricted to what the apps actually bundle translations for
            // (assets/translations) — accepting an arbitrary tag would let
            // a client set a locale that then falls back silently forever.
            // Further limited to what the admin has enabled.
            'language' => ['sometimes', 'required', 'string', Rule::in(Languages::enabled())],
            'enable_notification' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
