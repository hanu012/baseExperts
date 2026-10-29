<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/reviews (SPEC section 9, task 5.5). Takes `vendor_id`, not
 * `lead_id` — the client shouldn't have to track which lead it's
 * reviewing. This request resolves the customer's most recent lead with
 * that vendor that's both within the last 30 days and not already
 * reviewed (`reviews.lead_id` is unique, so "eligible" and "unreviewed"
 * are the same check), and stashes it on [eligibleLead] so the
 * controller doesn't have to re-query it.
 *
 * A vendor with two valid unreviewed leads from the same customer can
 * receive two reviews, one per lead — correct per SPEC's literal
 * "one review per lead" wording, not "one review per customer-vendor
 * pair."
 */
class StoreReviewRequest extends FormRequest
{
    public ?Lead $eligibleLead = null;

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
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customer = $this->user()?->customer;

            if ($customer === null || ! $this->filled('vendor_id')) {
                return;
            }

            $this->eligibleLead = Lead::where('customer_id', $customer->id)
                ->where('vendor_id', $this->integer('vendor_id'))
                ->where('created_at', '>=', now()->subDays(30))
                ->whereDoesntHave('review')
                ->latest('created_at')
                ->first();

            if ($this->eligibleLead === null) {
                $validator->errors()->add(
                    'vendor_id',
                    'No recent contact with this vendor was found within the last 30 days, or it has already been reviewed.'
                );
            }
        });
    }
}
