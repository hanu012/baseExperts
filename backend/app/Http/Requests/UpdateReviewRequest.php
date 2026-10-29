<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/reviews/{review} (SPEC section 4 item 9, task 5.5) — the
 * 24-hour edit window. Ownership and the time-window check aren't
 * expressible as field rules (they depend on the loaded Review, not
 * just the request body), so they're enforced in
 * ReviewController::update() instead, same style
 * AddSubscriptionItemsRequest already uses for whole-request business
 * rules.
 */
class UpdateReviewRequest extends FormRequest
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
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
