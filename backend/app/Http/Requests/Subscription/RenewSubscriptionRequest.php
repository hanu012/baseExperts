<?php

namespace App\Http\Requests\Subscription;

use App\Models\Vendor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Renewing a vendor who already had a plan — early while still active,
 * during grace, or after it expired. Same selection, quota, ownership and
 * self-service-is-online rules as a first subscription (inherited); the
 * differences are only who qualifies and that there is no free trial.
 */
class RenewSubscriptionRequest extends StoreSubscriptionRequest
{
    /** Vendors that have had a plan and may buy another. */
    public const RENEWABLE_STATUSES = ['active', 'grace', 'expired'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // A free trial is a first-time, salesman-granted thing (SPEC 2.2),
        // never a way to extend an existing vendor.
        $rules['payment_mode'] = ['required', Rule::in(['cash', 'online'])];
        unset($rules['free_trial_days']);

        return $rules;
    }

    protected function validateVendorStatus(Validator $validator, Vendor $vendor): void
    {
        if (! in_array($vendor->status, self::RENEWABLE_STATUSES, true)) {
            $validator->errors()->add(
                'vendor_id',
                $vendor->status === 'draft'
                    ? 'This vendor has no plan yet — subscribe them instead of renewing.'
                    : 'This vendor cannot be renewed right now (awaiting verification or rejected).'
            );
        }
    }
}
