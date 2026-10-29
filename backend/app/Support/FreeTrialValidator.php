<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Validation\Validator;

/**
 * The free-trial rules a `payment_mode=free` request must satisfy,
 * shared by every place a subscription can be granted for free (SPEC
 * section 2.2): `StoreSubscriptionRequest` (initial subscribe) and
 * `ChangeSubscriptionPlanRequest` (upgrade/downgrade, task 4.7).
 *
 * Extracted rather than left duplicated, same reasoning
 * ServiceSelectionValidator was — the two callers must not silently
 * drift apart on what counts as an allowed free grant.
 */
final class FreeTrialValidator
{
    public static function validate(Validator $validator, User $actor, Vendor $vendor, int $days): void
    {
        $maxDays = (int) Setting::get('free_trial_max_days', 15);

        if ($days > $maxDays) {
            $validator->errors()->add(
                'free_trial_days',
                "Free trials are capped at {$maxDays} days."
            );
        }

        // One free trial per phone number, ever (SPEC section 2.2) —
        // withTrashed, since a deleted-and-recreated vendor row must not
        // reset the cap.
        $phoneAlreadyUsedATrial = Subscription::query()
            ->whereNotNull('free_trial_days')
            ->whereHas('vendor', fn ($q) => $q->withTrashed()->where('phone', $vendor->phone))
            ->exists();

        if ($phoneAlreadyUsedATrial) {
            $validator->errors()->add(
                'free_trial_days',
                'This phone number has already had a free trial.'
            );
        }

        // Per-salesman-per-month cap. Scoped to the salesman flow only —
        // SPEC frames this cap in terms of a salesman's monthly grants;
        // there is no equivalent admin cap specified.
        $salesman = $actor->role === UserRole::Salesman ? $actor->salesman : null;

        if ($salesman === null) {
            return;
        }

        $maxGrantsPerMonth = (int) Setting::get('free_grants_per_salesman_month', 10);

        $grantsThisMonth = Subscription::query()
            ->where('salesman_id', $salesman->id)
            ->whereNotNull('free_trial_days')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($grantsThisMonth >= $maxGrantsPerMonth) {
            $validator->errors()->add(
                'free_trial_days',
                "You have reached this month's limit of {$maxGrantsPerMonth} free grants."
            );
        }
    }
}
