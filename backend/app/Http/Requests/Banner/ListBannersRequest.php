<?php

namespace App\Http\Requests\Banner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public banner-serving query (SPEC section 5 item 5) — which
 * banners to show right now, for one app and optionally one
 * placement slot within it.
 */
class ListBannersRequest extends FormRequest
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
            'target_app' => ['required', 'string', 'in:salesman,vendor,customer'],
            'position' => ['nullable', 'string', 'max:255'],
        ];
    }
}
