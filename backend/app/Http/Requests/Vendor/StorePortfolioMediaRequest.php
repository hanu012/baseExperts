<?php

namespace App\Http\Requests\Vendor;

use App\Models\Media;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Uploading one portfolio photo or video, within whatever quota is still
 * unused on the vendor's own active subscription (SPEC section 3 item 5,
 * task 4.5) — same "resolve everything from the caller, never a client id"
 * shape as AddSubscriptionItemsRequest (task 4.4), and the same
 * remaining-quota arithmetic (existing + new > max, not submitted > max).
 *
 * Deliberately NOT sharing a helper with AddSubscriptionItemsRequest's
 * validateRemainingQuota() — that would force an abstraction over a single
 * trivial inequality reused across two otherwise-unrelated domains
 * (subscription items vs. media), unlike the leaf-zone/category-pairing
 * rules that were genuinely worth extracting into ServiceSelectionValidator.
 */
class StorePortfolioMediaRequest extends FormRequest
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
        $type = $this->input('type');

        return [
            'type' => ['required', Rule::in(['image', 'video'])],
            'subcategory_id' => ['required', 'integer'],
            'file' => $type === 'video'
                // No client-side compression exists for video yet (see
                // PROGRESS.md's Before Launch Checklist) — the 50 MB cap is
                // the stated fallback, enforced here as the actual
                // authority, not just at the picker.
                ? ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:51200']
                : ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vendor = $this->user()->vendor;

            if ($vendor === null) {
                $validator->errors()->add('subscription', 'No vendor profile exists for this account.');

                return;
            }

            $subscription = $vendor->currentActiveSubscription();

            if ($subscription === null || $subscription->plan->quota === null) {
                $validator->errors()->add(
                    'subscription',
                    'You do not have an active subscription to upload portfolio media to.'
                );

                return;
            }

            $this->subscription = $subscription;

            if ($this->filled('subcategory_id')) {
                $this->validateSubcategoryIsSelected($validator, $subscription);
            }

            if ($this->filled('type')) {
                $this->validateRemainingQuota($validator, $vendor, $this->string('type')->toString());
            }
        });
    }

    /**
     * SPEC section 3 item 5: portfolio is organised under a subcategory the
     * vendor actually offers — not decorative, since a customer browsing a
     * subcategory should only see work photographed for it.
     */
    private function validateSubcategoryIsSelected(Validator $validator, Subscription $subscription): void
    {
        $selected = SubscriptionItem::query()
            ->where('subscription_id', $subscription->id)
            ->where('item_type', 'subcategory')
            ->where('item_id', $this->integer('subcategory_id'))
            ->exists();

        if (! $selected) {
            $validator->errors()->add(
                'subcategory_id',
                'You can only upload portfolio media for a subcategory you currently offer.'
            );
        }
    }

    private function validateRemainingQuota(Validator $validator, Vendor $vendor, string $type): void
    {
        // effectiveQuota() (task 4.7) folds in any purchased add-on
        // quantity on top of the bare plan limit.
        $max = $this->subscription->effectiveQuota($type === 'video' ? 'videos' : 'photos');

        // pending + approved count toward quota; only a rejected upload
        // frees its slot back up — otherwise "quota-capped" is meaningless,
        // a vendor could upload past the cap and simply wait in the queue.
        $used = Media::query()
            ->where('mediable_type', $vendor->getMorphClass())
            ->where('mediable_id', $vendor->id)
            ->where('type', $type)
            ->where('moderation_status', '!=', 'rejected')
            ->count();

        if ($used + 1 > $max) {
            $remaining = max(0, $max - $used);
            $label = $type === 'video' ? 'videos' : 'photos';

            $validator->errors()->add(
                'file',
                "This plan allows at most {$max} {$label} — {$remaining} remaining."
            );
        }
    }

    public function subscription(): Subscription
    {
        return $this->subscription;
    }
}
