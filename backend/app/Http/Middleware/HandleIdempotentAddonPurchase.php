<?php

namespace App\Http\Middleware;

use App\Http\Resources\SubscriptionAddonResource;
use App\Http\Responses\ApiResponse;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The add-on equivalent of HandleIdempotentSubscription (task 4.7) —
 * separate class, not a branch inside that one, since it looks up a
 * different model (SubscriptionAddon, not Subscription): an add-on
 * purchase never creates a new Subscription row, so
 * SubscriptionService::findByIdempotencyKey() would never find a
 * replayed add-on request at all.
 *
 * Unlike change-plan, a replayed add-on request wouldn't actually break
 * PurchaseAddOnRequest's OWN validation rules on retry (ownership and
 * "subscription is active" don't flip as a side effect of a successful
 * purchase) — but the header requirement and the short-circuit-before-
 * a-second-charge behavior stay in middleware anyway, for the same
 * "Idempotency-Key required" convention every subscription-mutating
 * endpoint follows.
 */
class HandleIdempotentAddonPurchase
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || ! Str::isUuid($key)) {
            return ApiResponse::error(
                'IDEMPOTENCY_KEY_REQUIRED',
                'A valid Idempotency-Key header (a UUID) is required.',
                422
            );
        }

        $existing = $this->subscriptions->findAddonByIdempotencyKey($key);

        if ($existing !== null) {
            return ApiResponse::success(['addon' => new SubscriptionAddonResource($existing)]);
        }

        return $next($request);
    }
}
