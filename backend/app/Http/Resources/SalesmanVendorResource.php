<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor as this salesman's "My Vendors" tab sees it (SPEC section 2.3):
 * name, plan, days to expiry. Leads-this-month is deliberately absent — the
 * leads table doesn't exist until Phase 5, and a column with nothing behind
 * it is worse than no column.
 *
 * `plan_name`/`days_to_expiry` are both nullable: a vendor still in Draft
 * has no subscription at all. Deliberately NOT filtered to active-only —
 * this takes the vendor's most recent subscription regardless of status, so
 * an expired vendor still shows its last plan with a negative
 * days-to-expiry ("expired 4 days ago") rather than going blank.
 */
class SalesmanVendorResource extends JsonResource
{
    /**
     * Used/max per sellable resource, or null when there is no active
     * subscription.
     *
     * Injected by the caller rather than resolved here: the whole list's
     * figures come from one batched pass (VendorQuotaUsageBatch), and a
     * resource that queried for its own would put that back to a handful
     * of queries per row.
     *
     * @var array<string, array<string, int>>|null
     */
    private ?array $quota = null;

    /**
     * @param  array<string, array<string, int>>|null  $quota
     */
    public function withQuota(?array $quota): self
    {
        $this->quota = $quota;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->whenLoaded('subscriptions', fn () => $this->subscriptions->first());
        $subscription = $subscription instanceof Subscription ? $subscription : null;

        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'owner_name' => $this->owner_name,
            'status' => $this->status,
            'plan_name' => $subscription?->plan?->name,
            'days_to_expiry' => $subscription === null
                ? null
                : now()->startOfDay()->diffInDays($subscription->end_date, absolute: false),
            'end_date' => $subscription?->end_date?->toDateString(),
            // Null means no active subscription — the "Not subscribed"
            // state the list already renders.
            'quota' => $this->quota,
        ];
    }
}
