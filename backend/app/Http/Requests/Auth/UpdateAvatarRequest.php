<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/user/avatar — one photo, as a multipart `photo` field. */
class UpdateAvatarRequest extends FormRequest
{
    /** KB. The apps compress before sending; this is the backstop. */
    public const MAX_KB = 5120;

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
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ];
    }
}
