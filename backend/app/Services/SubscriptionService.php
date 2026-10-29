<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Subscribing a vendor to a plan (SPEC section 6) — salesman/admin-led
 * (source=salesman/admin) and vendor self-service (source=self, task 4.2).
 *
 * In app/Services because it spans five tables in one transaction, which
 * CLAUDE.md puts here regardless of whether repositories are used
 * elsewhere — same reasoning as VendorDraftService. A half-applied
 * subscribe is the specific failure to avoid: a subscription with no
 * payment row, or an activated vendor with no commission a salesman is
 * owed, are both worse than the request simply failing outright.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly AccountInviteService $invites,
    ) {}

    /**
     * An existing subscription for this Idempotency-Key, if the header has
     * been seen before. Looked up BEFORE the transaction — a retried
     * request (dropped response, not a genuine second sale) must return the
     * same row, never attempt a second insert.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Subscription
    {
        return Subscription::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Same reasoning as findByIdempotencyKey() — the add-on equivalent,
     * looked up by HandleIdempotentAddonPurchase before the transaction.
     */
    public function findAddonByIdempotencyKey(string $idempotencyKey): ?SubscriptionAddon
    {
        return SubscriptionAddon::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $subcategoryIds
     * @param  array<int, int>  $zoneIds
     * @return array{subscription: Subscription, temporary_password: ?string, password_link_sent_to: ?string}
     */
    public function subscribe(
        Vendor $vendor,
        Plan $plan,
        array $categoryIds,
        array $subcategoryIds,
        array $zoneIds,
        string $paymentMode,
        ?int $freeTrialDays,
        User $actor,
        string $idempotencyKey,
    ): array {
        $result = DB::transaction(function () use (
            $vendor, $plan, $categoryIds, $subcategoryIds, $zoneIds,
            $paymentMode, $freeTrialDays, $actor, $idempotencyKey,
        ) {
            $isSalesman = $actor->role === UserRole::Salesman;
            $isVendorSelf = $actor->role === UserRole::Vendor;
            $salesman = $isSalesman ? $actor->salesman : null;

            $source = match (true) {
                $isSalesman => 'salesman',
                $isVendorSelf => 'self',
                default => 'admin',
            };

            $isFreeTrial = $paymentMode === 'free';

            // One live payment link per vendor: an earlier unpaid sale is
            // withdrawn first, so both can never be paid.
            $this->releasePendingSales($vendor->id);

            // Server computes price and dates from the plan — never trusts
            // a client-sent amount or end_date (SPEC section 6.1). A free
            // trial overrides both: it costs nothing and runs for the
            // salesman-chosen (quota-capped) duration, not the plan's.
            $pricePaise = $isFreeTrial ? 0 : $plan->price_paise;
            $durationDays = $isFreeTrial ? $freeTrialDays : $plan->duration_days;

            $startDate = now()->startOfDay();
            $endDate = $startDate->copy()->addDays($durationDays);

            $subscription = Subscription::create([
                'vendor_id' => $vendor->id,
                'plan_id' => $plan->id,
                'salesman_id' => $salesman?->id,
                'source' => $source,
                'status' => 'active',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'price_paise' => $pricePaise,
                'duration_days' => $durationDays,
                'free_trial_days' => $isFreeTrial ? $freeTrialDays : null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->recordItems($subscription, 'category', $categoryIds);
            $this->recordItems($subscription, 'subcategory', $subcategoryIds);
            $this->recordItems($subscription, 'zone', $zoneIds);

            $payment = null;

            if ($isFreeTrial) {
                $this->payments->recordFreeTrial($subscription);
            } elseif ($paymentMode === 'cash') {
                $this->payments->payViaCash($subscription, $pricePaise, $salesman);
            } else {
                $payment = $this->payments->payOnline($subscription, $pricePaise);
            }

            if ($payment?->isPending()) {
                // A Razorpay link is out and unpaid. Nothing goes live yet —
                // no commission, vendor still draft, and pending_payment is
                // outside every active/grace check — until the webhook calls
                // completeOnlinePayment(), which does all of it then.
                $subscription->update(['status' => 'pending_payment']);
            } else {
                // SPEC section 12: only a salesman-sourced, PAID sale earns
                // commission — never a free trial, never source = admin (the
                // subscriptions migration is explicit: salesman_id is null for
                // admin-created rows, so there is no salesman to credit).
                if ($isSalesman && ! $isFreeTrial && $salesman !== null) {
                    $this->recordCommission($subscription, $salesman, $pricePaise);
                }

                // Active only once verified (KYC documents + account
                // approval); otherwise pending_verification until an admin
                // approves — however the vendor was added.
                $vendor->update(['status' => $this->vendorStatusAfterPayment($subscription)]);
            }

            // The draft-stage password is unusable by design (a random
            // string nobody was given — see VendorDraftService::create()),
            // ONLY for a vendor created via that flow (salesman/admin-led).
            // A self-registered vendor already has their own chosen
            // password (task 4.1) — resetting it here and forcing a
            // must_change_password would lock them out of the account they
            // just used to sign in and subscribe.
            $temporaryPassword = null;

            if (! $isVendorSelf) {
                $temporaryPassword = User::generateTemporaryPassword();
                $vendor->user->forceFill([
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                ])->save();
            }

            return [
                'subscription' => $subscription,
                'temporary_password' => $temporaryPassword,
                'password_link_sent_to' => null,
            ];
        });

        // Firebase sign-in on: email the vendor a "set your password" link
        // instead of handing the salesman a password to read out. After the
        // commit, so a failed sale never sends one. If the email cannot go,
        // the temporary password set above is shown exactly as before.
        if ($result['temporary_password'] !== null) {
            $sentTo = $this->invites->send($vendor->user->fresh());

            if ($sentTo !== null) {
                $result['temporary_password'] = null;
                $result['password_link_sent_to'] = $sentTo;
            }
        }

        return $result;
    }

    /**
     * Upgrade/downgrade (SPEC section 3 item 6 / section 6, task 4.7) —
     * replaces the caller's active subscription with a fresh one on a
     * different plan, crediting the old subscription's unused days as a
     * price reduction (never a duration extension). The old row is
     * marked `superseded` (a real terminal state, distinct from
     * `cancelled` so admin reporting can tell an upsell apart from
     * churn), `end_date` moved to today, and the new row's
     * `previous_subscription_id` points back to it.
     *
     * One formula, both directions: the same proration math runs
     * whether the new plan is pricier or cheaper. A downgrade can
     * produce a credit larger than the new plan's price — the amount
     * charged floors at 0, and the excess is never refunded (no refund
     * mechanism exists without a real payment gateway; see SPEC section
     * 13). Quota-fit (the actual downgrade block) was already checked
     * by ChangeSubscriptionPlanRequest before this ever runs.
     *
     * `subscriptions.price_paise` on the NEW row is set to the amount
     * actually charged this time (the discounted amount), not the
     * plan's list price — consistent with how a free trial already sets
     * it to 0 rather than the plan's price. Commission is computed off
     * this same discounted amount.
     *
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $subcategoryIds
     * @param  array<int, int>  $zoneIds
     * @return array{subscription: Subscription, previous_subscription: Subscription}
     */
    public function changePlan(
        Subscription $current,
        Plan $newPlan,
        array $categoryIds,
        array $subcategoryIds,
        array $zoneIds,
        string $paymentMode,
        ?int $freeTrialDays,
        User $actor,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use (
            $current, $newPlan, $categoryIds, $subcategoryIds, $zoneIds,
            $paymentMode, $freeTrialDays, $actor, $idempotencyKey,
        ) {
            $isSalesman = $actor->role === UserRole::Salesman;
            $isVendorSelf = $actor->role === UserRole::Vendor;
            $salesman = $isSalesman ? $actor->salesman : null;

            $source = match (true) {
                $isSalesman => 'salesman',
                $isVendorSelf => 'self',
                default => 'admin',
            };

            $isFreeTrial = $paymentMode === 'free';
            $today = now()->startOfDay();

            $this->releasePendingSales($current->vendor_id);

            // Decision 1: the credit is a price reduction, never a
            // duration extension — the new subscription always runs the
            // new plan's own standard duration_days.
            $unusedDays = $today->lt($current->end_date) ? $today->diffInDays($current->end_date) : 0;
            $remainingValuePaise = (int) round($current->price_paise * $unusedDays / $current->duration_days);

            $newPlanPricePaise = $isFreeTrial ? 0 : $newPlan->price_paise;
            $amountDuePaise = $isFreeTrial ? 0 : max(0, $newPlanPricePaise - $remainingValuePaise);

            $durationDays = $isFreeTrial ? $freeTrialDays : $newPlan->duration_days;
            $endDate = $today->copy()->addDays($durationDays);

            $newSubscription = Subscription::create([
                'vendor_id' => $current->vendor_id,
                'plan_id' => $newPlan->id,
                'salesman_id' => $salesman?->id,
                'source' => $source,
                'status' => 'active',
                'start_date' => $today,
                'end_date' => $endDate,
                'price_paise' => $amountDuePaise,
                'duration_days' => $durationDays,
                'free_trial_days' => $isFreeTrial ? $freeTrialDays : null,
                'previous_subscription_id' => $current->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->recordItems($newSubscription, 'category', $categoryIds);
            $this->recordItems($newSubscription, 'subcategory', $subcategoryIds);
            $this->recordItems($newSubscription, 'zone', $zoneIds);

            $payment = null;

            if ($isFreeTrial) {
                $this->payments->recordFreeTrial($newSubscription);
            } elseif ($paymentMode === 'cash') {
                $this->payments->payViaCash($newSubscription, $amountDuePaise, $salesman);
            } else {
                $payment = $this->payments->payOnline($newSubscription, $amountDuePaise);
            }

            if ($payment?->isPending()) {
                // The current plan keeps running until the new one is paid
                // for; completeOnlinePayment() supersedes it then.
                $newSubscription->update(['status' => 'pending_payment']);
            } else {
                if ($isSalesman && ! $isFreeTrial && $salesman !== null) {
                    $this->recordCommission($newSubscription, $salesman, $amountDuePaise);
                }

                // Decision 6: a distinct terminal state from `cancelled` —
                // this row wasn't churn, it was replaced.
                $current->update(['status' => 'superseded', 'end_date' => $today]);
            }

            return [
                'subscription' => $newSubscription,
                'previous_subscription' => $current,
            ];
        });
    }

    /**
     * Renewal — a vendor who already had a plan buys another: early while
     * still active, during grace, or after it expired (RenewSubscriptionRequest
     * decides who qualifies). Any plan may be chosen, so this also covers
     * moving to a different plan at renewal time.
     *
     * Dates: the new term runs from the day after the current plan would
     * have ended, so renewing early never loses paid-for days; from grace or
     * expired it runs from today. The replaced plan is marked superseded so
     * a vendor never has two live plans (search joins assume one).
     *
     * No temporary password and no free trial — unlike subscribe(), the
     * vendor already has an account and has had a plan. Commission is
     * earned like any paid salesman sale. A Razorpay-held renewal does all
     * of this on payment instead (completeOnlinePayment()).
     *
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $subcategoryIds
     * @param  array<int, int>  $zoneIds
     */
    public function renew(
        Vendor $vendor,
        Plan $plan,
        array $categoryIds,
        array $subcategoryIds,
        array $zoneIds,
        string $paymentMode,
        User $actor,
        string $idempotencyKey,
    ): Subscription {
        return DB::transaction(function () use (
            $vendor, $plan, $categoryIds, $subcategoryIds, $zoneIds, $paymentMode, $actor, $idempotencyKey,
        ) {
            $isSalesman = $actor->role === UserRole::Salesman;
            $salesman = $isSalesman ? $actor->salesman : null;

            $source = match ($actor->role) {
                UserRole::Salesman => 'salesman',
                UserRole::Vendor => 'self',
                default => 'admin',
            };

            $this->releasePendingSales($vendor->id);

            // The plan being renewed: the live one if there is one,
            // otherwise the most recent that ran (for the history link).
            $current = $vendor->subscriptions()
                ->whereIn('status', ['active', 'grace'])
                ->latest('end_date')
                ->first();
            $previous = $current ?? $vendor->subscriptions()
                ->whereIn('status', ['expired', 'superseded', 'cancelled'])
                ->latest('end_date')
                ->first();

            $today = now()->startOfDay();
            $endDate = $this->renewalTermStart($current, $today)->addDays($plan->duration_days);

            $subscription = Subscription::create([
                'vendor_id' => $vendor->id,
                'plan_id' => $plan->id,
                'salesman_id' => $salesman?->id,
                'source' => $source,
                'status' => 'active',
                'start_date' => $today,
                'end_date' => $endDate,
                'price_paise' => $plan->price_paise,
                'duration_days' => $plan->duration_days,
                'previous_subscription_id' => $previous?->id,
                'is_renewal' => true,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->recordItems($subscription, 'category', $categoryIds);
            $this->recordItems($subscription, 'subcategory', $subcategoryIds);
            $this->recordItems($subscription, 'zone', $zoneIds);

            $payment = $paymentMode === 'cash'
                ? $this->payments->payViaCash($subscription, $plan->price_paise, $salesman)
                : $this->payments->payOnline($subscription, $plan->price_paise);

            if ($payment->isPending()) {
                // Held for Razorpay: the current plan keeps running (and the
                // vendor stays listed) until this is paid.
                $subscription->update(['status' => 'pending_payment']);

                return $subscription;
            }

            if ($salesman !== null) {
                $this->recordCommission($subscription, $salesman, $plan->price_paise);
            }

            $current?->update(['status' => 'superseded', 'end_date' => $today]);

            // Back to active from grace or expired. A vendor already active
            // stays so.
            $vendor->update(['status' => 'active']);

            return $subscription;
        });
    }

    /**
     * Where a renewed term starts counting from: after the replaced plan's
     * end date while that plan is still running, so early renewal keeps
     * every paid day; otherwise today.
     */
    private function renewalTermStart(?Subscription $replaced, \Illuminate\Support\Carbon $today): \Illuminate\Support\Carbon
    {
        if ($replaced !== null
            && in_array($replaced->status, ['active', 'grace'], true)
            && $replaced->end_date !== null
            && $replaced->end_date->gte($today)) {
            return $replaced->end_date->copy()->startOfDay();
        }

        return $today->copy();
    }

    /**
     * Buying quota on top of the base plan (SPEC section 3 item 6 /
     * section 6, task 4.7) — attaches to the CURRENT subscription (no
     * new subscription row, no vendor-status change), price computed
     * server-side from `plan_quotas`' per-resource unit price, never
     * trusted from the client.
     *
     * NO COMMISSION IS RECORDED HERE, even for a salesman actor — a
     * real limitation, not an oversight: `commissions.subscription_id`
     * is UNIQUE (one commission per subscription, tied to its original
     * sale), so a second `Commission::create()` against the same
     * subscription an add-on attaches to would throw a constraint
     * violation. Extending commissions to also credit ongoing add-on
     * purchases would need a schema change (drop the uniqueness, add
     * add-on linkage) that's out of scope for this task — flagged here
     * and in PROGRESS.md, not silently dropped. `purchaseAddOn()` still
     * requires the same role/ownership gate as `changePlan()`
     * (decision 3) for consistency and the upsell reasoning there, even
     * though no commission is earned on the sale today.
     */
    public function purchaseAddOn(
        Subscription $subscription,
        string $resource,
        int $quantity,
        string $paymentMode,
        string $idempotencyKey,
    ): SubscriptionAddon {
        return DB::transaction(function () use ($subscription, $resource, $quantity, $paymentMode, $idempotencyKey) {
            $unitPricePaise = $subscription->plan->quota->addonPricePerUnit($resource);
            $pricePaise = $unitPricePaise * $quantity;

            $addon = SubscriptionAddon::create([
                'subscription_id' => $subscription->id,
                'resource' => $resource,
                'quantity' => $quantity,
                'price_paise' => $pricePaise,
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($paymentMode === 'cash') {
                $this->payments->payViaCash($subscription, $pricePaise, $subscription->salesman);
            } else {
                $payment = $this->payments->payOnline($subscription, $pricePaise, $addon);

                // Not counted toward quota (Subscription::effectiveQuota
                // sums paid add-ons only) until Razorpay confirms it.
                if ($payment->isPending()) {
                    $addon->forceFill(['status' => 'pending_payment'])->save();
                }
            }

            return $addon;
        });
    }

    /**
     * Razorpay reported the payment link paid (webhook). Does everything
     * subscribe()/changePlan()/purchaseAddOn() held back for it.
     *
     * Idempotent: Razorpay retries webhooks, so a payment already captured
     * is left alone. Row-locked so two deliveries racing cannot both
     * activate.
     */
    public function completeOnlinePayment(Payment $payment, ?string $gatewayPaymentId): void
    {
        DB::transaction(function () use ($payment, $gatewayPaymentId) {
            $payment = Payment::query()->lockForUpdate()->find($payment->id);

            if ($payment === null || $payment->status === 'captured') {
                return;
            }

            if ($payment->status !== 'pending') {
                // Paid in the moment between the link being withdrawn (a
                // newer sale, or expiry) and Razorpay noticing. The money is
                // real, so it is recorded — but nothing is activated on top
                // of the sale that replaced it. An admin refunds it.
                $payment->forceFill([
                    'status' => 'captured',
                    'gateway_payment_id' => $gatewayPaymentId,
                ])->save();

                Log::critical('Razorpay payment captured for a withdrawn sale — refund needed', [
                    'payment_id' => $payment->id,
                    'gateway_payment_id' => $gatewayPaymentId,
                ]);

                return;
            }

            $payment->forceFill([
                'status' => 'captured',
                'gateway_payment_id' => $gatewayPaymentId,
                'admin_verified_at' => now(),
            ])->save();

            if ($payment->subscription_addon_id !== null) {
                $payment->addon?->forceFill(['status' => 'paid'])->save();

                return;
            }

            $subscription = $payment->subscription;

            if ($subscription === null || $subscription->status !== 'pending_payment') {
                return;
            }

            // The term starts when it is paid for, not when the link went
            // out — a vendor who pays a day late still gets the full term.
            $today = now()->startOfDay();

            $previous = $subscription->previous_subscription_id !== null
                ? Subscription::query()->find($subscription->previous_subscription_id)
                : null;
            $previousLive = $previous !== null && in_array($previous->status, ['active', 'grace'], true);

            $subscription->update([
                'status' => 'active',
                'start_date' => $today,
                'end_date' => $this->renewalTermStart($subscription->is_renewal ? $previous : null, $today)
                    ->addDays($subscription->duration_days),
            ]);

            if ($subscription->salesman !== null) {
                $this->recordCommission($subscription, $subscription->salesman, $payment->amount_paise);
            }

            // A plan change or renewal: the plan it replaces ran until now.
            if ($previousLive) {
                $previous->update(['status' => 'superseded', 'end_date' => $today]);
            }

            if ($subscription->is_renewal) {
                $subscription->vendor?->update(['status' => 'active']);
            } elseif ($subscription->previous_subscription_id === null) {
                $subscription->vendor?->update(['status' => $this->vendorStatusAfterPayment($subscription)]);
            }
        });
    }

    /**
     * Razorpay reported the link expired or cancelled. The sale is off:
     * the payment fails, a held subscription is cancelled, a held add-on
     * is cancelled. A captured payment is never undone here.
     */
    public function failOnlinePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::query()->lockForUpdate()->find($payment->id);

            if ($payment === null || $payment->status !== 'pending') {
                return;
            }

            $payment->forceFill(['status' => 'failed'])->save();

            if ($payment->subscription_addon_id !== null) {
                $payment->addon?->forceFill(['status' => 'cancelled'])->save();

                return;
            }

            $payment->subscription()
                ->where('status', 'pending_payment')
                ->update(['status' => 'cancelled']);
        });
    }

    /**
     * Withdraws every unpaid sale for this vendor: the link is cancelled at
     * Razorpay first (if that fails, the new sale is refused — see
     * RazorpayClient::cancelPaymentLink), then the held subscription is
     * cancelled. Lets a salesman switch an unpaid online sale to cash, and
     * stops a vendor ever paying two links.
     */
    private function releasePendingSales(int $vendorId): void
    {
        $pending = Payment::query()
            ->where('status', 'pending')
            ->whereNull('subscription_addon_id')
            ->whereHas('subscription', fn ($query) => $query
                ->where('vendor_id', $vendorId)
                ->where('status', 'pending_payment'))
            ->get();

        foreach ($pending as $payment) {
            $this->payments->cancelOnline($payment);
            $this->failOnlinePayment($payment);
        }
    }

    /**
     * Active only for a vendor already through verification: account
     * approved AND every compulsory KYC document Verified (decided
     * 2026-10-28 — replaces SPEC section 7's "salesman/admin-added go
     * straight to active"). Anyone else, however they were added, waits in
     * pending_verification until an admin approves them
     * (VendorVerificationService::approve moves them on to active).
     */
    private function vendorStatusAfterPayment(Subscription $subscription): string
    {
        $vendor = $subscription->vendor;

        return $vendor?->user?->isApproved() && app(VendorKycService::class)->isComplete($vendor)
            ? 'active'
            : 'pending_verification';
    }

    /**
     * Shared by subscribe(), changePlan() and purchaseAddOn() — SPEC
     * section 12: only a salesman-sourced, paid sale earns commission.
     * Callers already check `$isFreeTrial`/`$salesman !== null` before
     * calling this; kept explicit at each call site rather than folded
     * in here, since "when is commission owed" reads more clearly next
     * to the code that just decided how much money changed hands.
     */
    private function recordCommission(Subscription $subscription, Salesman $salesman, int $amountPaise): void
    {
        Commission::create([
            'subscription_id' => $subscription->id,
            'salesman_id' => $salesman->id,
            // Integer-only, round-half-up. amount_paise * rate_bps
            // divided by 10000 as a float can misround the same way
            // Plan::rupeesToPaise() guards against — 1/10000 has no
            // exact binary representation, so this stays in integers
            // throughout rather than trusting round() on a float.
            'amount_paise' => intdiv($amountPaise * $salesman->commission_rate_bps + 5000, 10000),
            // Snapshot, not a live read — a later rate change must not
            // rewrite what this sale actually earned (same reasoning as
            // Plan's audit snapshot).
            'rate_bps' => $salesman->commission_rate_bps,
            'status' => 'pending',
        ]);
    }

    /**
     * Adds categories/subcategories/zones to an already-active subscription,
     * within whatever quota is still unused (SPEC section 3.3, task 4.4) —
     * distinct from subscribe(), which only ever creates a fresh
     * subscription. The caller (AddSubscriptionItemsRequest) has already
     * computed which ids are genuinely new and validated them against
     * remaining quota; this method just inserts them, same insert-only
     * shape recordItems() already has. No commission, no payment, no
     * vendor-status change — this is purely additive to an existing,
     * already-paid-for subscription.
     *
     * Also removes items when the vendor app unticks something it picked
     * earlier ($removed*, replace mode — see AddSubscriptionItemsRequest).
     * Removal only drops the selection; nothing is refunded, and the room it
     * frees is simply available to pick something else. Leads and
     * portfolio media filed under a removed subcategory are kept: the leads
     * are history, and the media is hidden from customers until that
     * service is picked again.
     *
     * @param  array<int, int>  $newCategoryIds
     * @param  array<int, int>  $newSubcategoryIds
     * @param  array<int, int>  $newZoneIds
     * @param  array<int, int>  $removedCategoryIds
     * @param  array<int, int>  $removedSubcategoryIds
     * @param  array<int, int>  $removedZoneIds
     */
    public function addItems(
        Subscription $subscription,
        array $newCategoryIds,
        array $newSubcategoryIds,
        array $newZoneIds,
        array $removedCategoryIds = [],
        array $removedSubcategoryIds = [],
        array $removedZoneIds = [],
    ): Subscription {
        return DB::transaction(function () use (
            $subscription, $newCategoryIds, $newSubcategoryIds, $newZoneIds,
            $removedCategoryIds, $removedSubcategoryIds, $removedZoneIds,
        ) {
            $removed = [
                'category' => $removedCategoryIds,
                'subcategory' => $removedSubcategoryIds,
                'zone' => $removedZoneIds,
            ];

            foreach ($removed as $itemType => $ids) {
                if ($ids !== []) {
                    SubscriptionItem::query()
                        ->where('subscription_id', $subscription->id)
                        ->where('item_type', $itemType)
                        ->whereIn('item_id', $ids)
                        ->delete();
                }
            }

            $this->recordItems($subscription, 'category', $newCategoryIds);
            $this->recordItems($subscription, 'subcategory', $newSubcategoryIds);
            $this->recordItems($subscription, 'zone', $newZoneIds);

            // SubscriptionItem rows carry no audit trail of their own, so a
            // change to what a paid subscription covers is logged on the
            // subscription itself.
            if (array_filter($removed) !== []) {
                $subscription->writeAuditLog(
                    'services_changed',
                    ['removed' => array_filter($removed)],
                    ['added' => array_filter([
                        'category' => $newCategoryIds,
                        'subcategory' => $newSubcategoryIds,
                        'zone' => $newZoneIds,
                    ])],
                );
            }

            return $subscription->fresh();
        });
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function recordItems(Subscription $subscription, string $itemType, array $ids): void
    {
        $rows = array_map(fn (int $id) => [
            'subscription_id' => $subscription->id,
            'item_type' => $itemType,
            'item_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        // Deduplicated: a repeated id passes validation (it is still a
        // valid leaf) but would hit the subscription_items unique index.
        ], array_values(array_unique($ids)));

        if ($rows !== []) {
            SubscriptionItem::insert($rows);
        }
    }
}
