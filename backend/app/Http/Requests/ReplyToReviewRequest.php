<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/vendors/me/reviews/{review}/reply (SPEC section 4 item 9,
 * task 5.5) — a vendor's right of reply on a review of their own
 * listing. Ownership (is this review even on the caller's vendor
 * profile?) is checked in VendorReviewController::reply(), not here.
 */
class ReplyToReviewRequest extends FormRequest
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
            'reply' => ['required', 'string', 'max:2000'],
        ];
    }
}
