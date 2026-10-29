<?php

namespace App\Http\Requests\Subscription;

use App\Enums\UserRole;
use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buying quota on top of the base plan (SPEC section 3 item 6 / section
 * 6, task 4.7) — "+2 categories" without changing the plan. Same
 * ownership/role rules as ChangeSubscriptionPlanRequest: this is
 * money-bearing, so it follows subscribe's dual-path pattern
 * (salesman/admin/vendor with ownership checks), not task 4.4's
 * vendor-only add-services (which is explicitly payment-free).
 *
 * One resource per call, stated as a simplicity choice — call it twice
 * for two resources rather than a multi-resource payload shape.
 *
 * `payment_mode` is `cash`/`online` only, no `free` — a free TRIAL is a
 * full-subscription concept (SPEC section 2.2), not something that
 * extends to "free extra quota"; nothing in SPEC describes a free
 * add-on, so it's simply not an option here.
 */
class PurchaseAddOnRequest extends FormRequest
{
    private ?Subscription $subscription = null;

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
            'resource' => ['required', 'string', Rule::in(['categories', 'subcategories', 'zones', 'photos', 'videos'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'payment_mode' => ['required', Rule::in(['cash', 'online'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $subscription = $this->route('subscription');

            if (! $subscription instanceof Subscription) {
                return;
            }

            $this->subscription = $subscription;

            $this->validateOwnership($validator, $subscription);
            $this->validateSubscriptionIsActive($validator, $subscription);
            $this->validatePaymentModeForRole($validator);
        });
    }

    private function validateOwnership(Validator $validator, Subscription $subscription): void
    {
        $actor = $this->user();
        $vendor = $subscription->vendor;

        if ($actor->role === UserRole::Salesman && $vendor->created_by_salesman_id !== $actor->salesman?->id) {
            $validator->errors()->add('subscription', 'You can only buy add-ons for a vendor you sold.');
        }

        if ($actor->role === UserRole::Vendor && $vendor->id !== $actor->vendor?->id) {
            $validator->errors()->add('subscription', 'You can only buy add-ons for your own subscription.');
        }
    }

    private function validateSubscriptionIsActive(Validator $validator, Subscription $subscription): void
    {
        if ($subscription->status !== 'active') {
            $validator->errors()->add('subscription', 'Only an active subscription can buy add-ons.');
        }
    }

    private function validatePaymentModeForRole(Validator $validator): void
    {
        if ($this->user()->role === UserRole::Vendor && $this->input('payment_mode') !== 'online') {
            $validator->errors()->add('payment_mode', 'Self-service add-on purchases must be paid online.');
        }
    }

    public function subscription(): Subscription
    {
        return $this->subscription;
    }
}
