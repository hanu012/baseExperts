<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\ChangeSubscriptionPlanRequest;
use App\Http\Requests\Subscription\PurchaseAddOnRequest;
use App\Http\Requests\Subscription\RenewSubscriptionRequest;
use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionAddonResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Vendor;
use App\Services\Payments\PaymentGatewayException;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Subscribes a vendor to a plan (SPEC section 6) — salesman/admin-led
     * only. By the time this runs, HandleIdempotentSubscription has already
     * confirmed the Idempotency-Key header is valid and unused, so a fresh
     * subscription is always the right thing to create here.
     */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $result = $this->subscriptions->subscribe(
                vendor: $vendor,
                plan: $plan,
                categoryIds: $data['category_ids'],
                subcategoryIds: $data['subcategory_ids'],
                zoneIds: $data['zone_ids'],
                paymentMode: $data['payment_mode'],
                freeTrialDays: $data['free_trial_days'] ?? null,
                actor: $request->user(),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (PaymentGatewayException $e) {
            report($e);

            return $this->gatewayUnavailable();
        } catch (QueryException $e) {
            // The race HandleIdempotentSubscription's pre-check cannot
            // close on its own: two near-simultaneous requests with the
            // same new key can both pass that check before either has
            // inserted. The unique column is the real guarantee; this is
            // just turning its violation into the same replay response
            // instead of a raw 500.
            if (! $this->isDuplicateIdempotencyKey($e)) {
                throw $e;
            }

            $existing = $this->subscriptions->findByIdempotencyKey($request->header('Idempotency-Key'));

            return ApiResponse::success([
                'subscription' => new SubscriptionResource(
                    $existing->load(['plan', 'items', 'payments', 'commission'])
                ),
            ]);
        }

        $subscription = $result['subscription']->load(['plan', 'items', 'payments', 'commission']);
        $vendor = $vendor->fresh();

        return ApiResponse::success([
            'subscription' => new SubscriptionResource($subscription),
            'vendor' => ['id' => $vendor->id, 'status' => $vendor->status],
            // Shown exactly once, per SPEC section 2.2 — the salesman
            // shares this via a WhatsApp share action right now, since it
            // cannot be recovered afterward.
            'temporary_password' => $result['temporary_password'],
            // Firebase sign-in on: the vendor was emailed a "set your
            // password" link instead (masked address), and
            // temporary_password is null. Null when no link went out.
            'password_link_sent_to' => $result['password_link_sent_to'],
        ], 201);
    }

    /**
     * Renewal — see SubscriptionService::renew(). Same Idempotency-Key
     * handling as store(): HandleIdempotentSubscription replays an earlier
     * success before validation runs.
     */
    public function renew(RenewSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $subscription = $this->subscriptions->renew(
                vendor: $vendor,
                plan: $plan,
                categoryIds: $data['category_ids'],
                subcategoryIds: $data['subcategory_ids'],
                zoneIds: $data['zone_ids'],
                paymentMode: $data['payment_mode'],
                actor: $request->user(),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (PaymentGatewayException $e) {
            report($e);

            return $this->gatewayUnavailable();
        } catch (QueryException $e) {
            if (! $this->isDuplicateIdempotencyKey($e)) {
                throw $e;
            }

            $existing = $this->subscriptions->findByIdempotencyKey($request->header('Idempotency-Key'));

            return ApiResponse::success([
                'subscription' => new SubscriptionResource(
                    $existing->load(['plan', 'items', 'payments', 'commission'])
                ),
            ]);
        }

        return ApiResponse::success([
            'subscription' => new SubscriptionResource(
                $subscription->load(['plan', 'items', 'payments', 'commission'])
            ),
            'vendor' => ['id' => $vendor->id, 'status' => $vendor->fresh()->status],
        ], 201);
    }

    /**
     * Upgrade/downgrade (SPEC section 3 item 6 / section 6, task 4.7).
     * `$subscription` is the OLD, still-active row (route-bound) — by
     * the time this runs it has already passed
     * ChangeSubscriptionPlanRequest's ownership/quota/downgrade-blocking
     * checks.
     */
    public function changePlan(ChangeSubscriptionPlanRequest $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validated();
        $newPlan = Plan::findOrFail($data['plan_id']);

        try {
            $result = $this->subscriptions->changePlan(
                current: $subscription,
                newPlan: $newPlan,
                categoryIds: $data['category_ids'],
                subcategoryIds: $data['subcategory_ids'],
                zoneIds: $data['zone_ids'],
                paymentMode: $data['payment_mode'],
                freeTrialDays: $data['free_trial_days'] ?? null,
                actor: $request->user(),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (PaymentGatewayException $e) {
            report($e);

            return $this->gatewayUnavailable();
        } catch (QueryException $e) {
            if (! $this->isDuplicateIdempotencyKey($e)) {
                throw $e;
            }

            $existing = $this->subscriptions->findByIdempotencyKey($request->header('Idempotency-Key'));

            return ApiResponse::success([
                'subscription' => new SubscriptionResource(
                    $existing->load(['plan', 'items', 'payments', 'commission'])
                ),
            ]);
        }

        $newSubscription = $result['subscription']->load(['plan', 'items', 'payments', 'commission']);
        $previousSubscription = $result['previous_subscription']->fresh();

        return ApiResponse::success([
            'subscription' => new SubscriptionResource($newSubscription),
            'previous_subscription' => [
                'id' => $previousSubscription->id,
                'status' => $previousSubscription->status,
                'end_date' => $previousSubscription->end_date?->toDateString(),
            ],
        ], 201);
    }

    /**
     * Buying quota on top of the base plan (SPEC section 3 item 6 /
     * section 6, task 4.7).
     */
    public function addOns(PurchaseAddOnRequest $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validated();

        try {
            $addon = $this->subscriptions->purchaseAddOn(
                subscription: $subscription,
                resource: $data['resource'],
                quantity: $data['quantity'],
                paymentMode: $data['payment_mode'],
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (PaymentGatewayException $e) {
            report($e);

            return $this->gatewayUnavailable();
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'idempotency_key')) {
                throw $e;
            }

            $existing = $this->subscriptions->findAddonByIdempotencyKey($request->header('Idempotency-Key'));

            return ApiResponse::success(['addon' => new SubscriptionAddonResource($existing)]);
        }

        return ApiResponse::success(['addon' => new SubscriptionAddonResource($addon)], 201);
    }

    /**
     * Razorpay is switched on but could not create the link. Everything
     * rolled back and the Idempotency-Key was never stored, so the app can
     * simply retry — or the salesman can take cash instead.
     */
    private function gatewayUnavailable(): JsonResponse
    {
        return ApiResponse::error(
            'PAYMENT_GATEWAY_UNAVAILABLE',
            'Online payment is unavailable right now. Please try again, or choose another payment mode.',
            503
        );
    }

    private function isDuplicateIdempotencyKey(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'idempotency_key');
    }
}
