<?php

namespace App\Http\Requests\Subscription;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\FreeTrialValidator;
use App\Support\ServiceSelectionValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Upgrade/downgrade (SPEC section 3 item 6 / section 6, task 4.7) — a
 * plan change against the caller's already-`active` subscription
 * (route-bound as `{subscription}`, not a client-sent `vendor_id`).
 * Ownership/payment-mode rules mirror StoreSubscriptionRequest exactly
 * (same trust boundary: this is money-bearing, just like a fresh
 * subscribe, unlike task 4.4's free add-services).
 *
 * `category_ids`/`subcategory_ids`/`zone_ids` are the COMPLETE desired
 * selection, not a delta — there's no "remove item" endpoint anywhere in
 * this codebase, so "deselecting down to the new plan's limit" (SPEC's
 * downgrade wording) means simply not re-including something in this
 * request, exactly like a fresh subscribe already requires the full set
 * every time.
 *
 * Photos/videos can't be resubmitted this way (they're uploaded files,
 * not ids) — downgrade-blocking for those two checks EXISTING Media
 * usage against the new plan's effective quota instead. This is the one
 * place this request looks at current state rather than the request
 * body; see validatePhotoVideoUsage().
 */
class ChangeSubscriptionPlanRequest extends FormRequest
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
            'plan_id' => ['required', 'integer', 'exists:plans,id'],

            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer'],

            'subcategory_ids' => ['required', 'array', 'min:1'],
            'subcategory_ids.*' => ['integer'],

            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer'],

            'payment_mode' => ['required', Rule::in(['cash', 'online', 'free'])],
            'free_trial_days' => ['nullable', 'integer', 'min:1', 'required_if:payment_mode,free'],
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

            $plan = $this->filled('plan_id') ? Plan::with('quota')->find($this->integer('plan_id')) : null;
            $this->validatePlan($validator, $plan);
            $this->validateSelections($validator, $plan);

            if ($plan?->quota !== null) {
                $this->validatePhotoVideoUsage($validator, $subscription, $plan);
            }

            if ($this->input('payment_mode') === 'free') {
                FreeTrialValidator::validate(
                    $validator,
                    $this->user(),
                    $subscription->vendor,
                    (int) $this->input('free_trial_days'),
                );
            }
        });
    }

    private function validateOwnership(Validator $validator, Subscription $subscription): void
    {
        $actor = $this->user();
        $vendor = $subscription->vendor;

        if ($actor->role === UserRole::Salesman && $vendor->created_by_salesman_id !== $actor->salesman?->id) {
            $validator->errors()->add('subscription', 'You can only change a plan you sold.');
        }

        if ($actor->role === UserRole::Vendor && $vendor->id !== $actor->vendor?->id) {
            $validator->errors()->add('subscription', 'You can only change your own subscription.');
        }
    }

    private function validateSubscriptionIsActive(Validator $validator, Subscription $subscription): void
    {
        if ($subscription->status !== 'active') {
            $validator->errors()->add('subscription', 'Only an active subscription can change plans.');
        }
    }

    /**
     * Same restriction StoreSubscriptionRequest applies — self-service is
     * online-only, a free trial is a salesman-granted concept.
     */
    private function validatePaymentModeForRole(Validator $validator): void
    {
        if ($this->user()->role === UserRole::Vendor && $this->input('payment_mode') !== 'online') {
            $validator->errors()->add('payment_mode', 'Self-service plan changes must be paid online.');
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

        // Absolute check against the NEW plan — this request always
        // carries the complete desired selection, same as a fresh
        // subscribe, so "downgrade blocked until deselected" is simply
        // this check failing when too much is still selected.
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

    /**
     * Photos/videos can't be resubmitted (they're uploaded files, not
     * ids) — downgrade-blocking for these two checks EXISTING usage
     * against the new plan's quota instead, the only dimension where
     * this request looks at current state rather than its own body.
     */
    private function validatePhotoVideoUsage(Validator $validator, Subscription $subscription, Plan $plan): void
    {
        $vendor = $subscription->vendor;

        $counts = Media::query()
            ->where('mediable_type', $vendor->getMorphClass())
            ->where('mediable_id', $vendor->id)
            ->where('moderation_status', '!=', 'rejected')
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $usedPhotos = (int) ($counts['image'] ?? 0);
        $usedVideos = (int) ($counts['video'] ?? 0);

        if ($usedPhotos > $plan->quota->max_photos) {
            $validator->errors()->add(
                'plan_id',
                "This plan allows at most {$plan->quota->max_photos} photos — {$usedPhotos} are already uploaded. Remove some before downgrading."
            );
        }

        if ($usedVideos > $plan->quota->max_videos) {
            $validator->errors()->add(
                'plan_id',
                "This plan allows at most {$plan->quota->max_videos} videos — {$usedVideos} are already uploaded. Remove some before downgrading."
            );
        }
    }

    /**
     * Populated during validation, matching AddSubscriptionItemsRequest's
     * own shape — the controller acts on the exact row validation
     * already checked, not a fresh query.
     */
    public function subscription(): Subscription
    {
        return $this->subscription;
    }
}
