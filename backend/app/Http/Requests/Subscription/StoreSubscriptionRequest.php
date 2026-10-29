<?php

namespace App\Http\Requests\Subscription;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Vendor;
use App\Support\FreeTrialValidator;
use App\Support\ServiceSelectionValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Subscribing a vendor to a plan (SPEC section 6) — salesman/admin-led
 * (source=salesman/admin) and vendor self-service (source=self, task 4.2).
 *
 * Structural checks live in rules(); everything that needs a loaded model
 * (quota counts, leaf-only zones, subcategory/category consistency, the
 * free-trial caps) lives in withValidator()->after(), same split as
 * StoreKycRequest.
 *
 * QUOTA AND SELECTION CHECKS ARE RE-DONE HERE SERVER-SIDE ON PURPOSE, even
 * though the Flutter step-2 screen already enforces the same rules
 * client-side (cascade-select, leaf-only zones, quota-capped checkboxes).
 * CLAUDE.md: the server never trusts client-sent values — a stale app
 * build, a buggy client, or a direct API call must not be able to bypass
 * quota or select a parent zone.
 */
class StoreSubscriptionRequest extends FormRequest
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
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],

            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer'],

            'subcategory_ids' => ['required', 'array', 'min:1'],
            'subcategory_ids.*' => ['integer'],

            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer'],

            'payment_mode' => ['required', Rule::in(['cash', 'online', 'free'])],

            // Only meaningful (and only required) for a free trial — the
            // salesman-selected duration, capped below by the admin Setting.
            'free_trial_days' => ['nullable', 'integer', 'min:1', 'required_if:payment_mode,free'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vendor = $this->filled('vendor_id') ? Vendor::find($this->integer('vendor_id')) : null;
            $plan = $this->filled('plan_id') ? Plan::with('quota')->find($this->integer('plan_id')) : null;

            $this->validateVendor($validator, $vendor);
            $this->validatePlan($validator, $plan);
            $this->validateSelections($validator, $plan);
            $this->validatePaymentModeForRole($validator);

            if ($this->input('payment_mode') === 'free' && $vendor !== null) {
                $this->validateFreeTrial($validator, $vendor);
            }
        });
    }

    private function validateVendor(Validator $validator, ?Vendor $vendor): void
    {
        if ($vendor === null) {
            // exists:vendors,id already reported the missing/invalid id.
            return;
        }

        $this->validateVendorStatus($validator, $vendor);

        $actor = $this->user();

        if ($actor->role === UserRole::Salesman
            && $vendor->created_by_salesman_id !== $actor->salesman?->id) {
            $validator->errors()->add('vendor_id', 'You can only subscribe a vendor you added.');
        }

        if ($actor->role === UserRole::Vendor && $vendor->id !== $actor->vendor?->id) {
            $validator->errors()->add('vendor_id', 'You can only subscribe your own account.');
        }
    }

    /**
     * A first subscription is for a draft vendor only. A vendor who already
     * had a plan (active, grace or expired) renews instead —
     * RenewSubscriptionRequest overrides this.
     */
    protected function validateVendorStatus(Validator $validator, Vendor $vendor): void
    {
        if ($vendor->status !== 'draft') {
            $validator->errors()->add(
                'vendor_id',
                'This vendor is not awaiting subscription (already subscribed, or not yet drafted).'
            );
        }
    }

    /**
     * SPEC section 3.2: self-service is mode=online only — a vendor cannot
     * hand themselves cash, and a free trial is a salesman-granted concept
     * (SPEC section 2.2's "Join as Free"), never self-service.
     */
    private function validatePaymentModeForRole(Validator $validator): void
    {
        if ($this->user()->role === UserRole::Vendor && $this->input('payment_mode') !== 'online') {
            $validator->errors()->add('payment_mode', 'Self-service subscription must be paid online.');
        }
    }

    private function validatePlan(Validator $validator, ?Plan $plan): void
    {
        if ($plan === null) {
            return;
        }

        if (! $plan->is_active) {
            $validator->errors()->add('plan_id', 'This plan is no longer available.');
        }

        if ($plan->quota === null) {
            $validator->errors()->add('plan_id', 'This plan has no quota configured.');
        }
    }

    private function validateSelections(Validator $validator, ?Plan $plan): void
    {
        $categoryIds = array_map('intval', (array) $this->input('category_ids', []));
        $subcategoryIds = array_map('intval', (array) $this->input('subcategory_ids', []));
        $zoneIds = array_map('intval', (array) $this->input('zone_ids', []));

        ServiceSelectionValidator::validate($validator, $categoryIds, $subcategoryIds, $zoneIds);

        if ($plan?->quota === null) {
            return;
        }

        $quota = $plan->quota;

        if (count($categoryIds) > $quota->max_categories) {
            $validator->errors()->add(
                'category_ids',
                "This plan allows at most {$quota->max_categories} categories."
            );
        }

        if (count($subcategoryIds) > $quota->max_subcategories) {
            $validator->errors()->add(
                'subcategory_ids',
                "This plan allows at most {$quota->max_subcategories} subcategories."
            );
        }

        if (count($zoneIds) > $quota->max_zones) {
            $validator->errors()->add(
                'zone_ids',
                "This plan allows at most {$quota->max_zones} zones."
            );
        }
    }

    private function validateFreeTrial(Validator $validator, Vendor $vendor): void
    {
        FreeTrialValidator::validate($validator, $this->user(), $vendor, (int) $this->input('free_trial_days'));
    }
}
