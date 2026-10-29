<?php

namespace App\Http\Middleware;

use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the Idempotency-Key header (SPEC section 6.3) and short-circuits
 * a replay BEFORE the request reaches StoreSubscriptionRequest.
 *
 * THIS HAS TO HAPPEN IN MIDDLEWARE, NOT THE CONTROLLER. Laravel resolves and
 * validates a type-hinted FormRequest before the controller method body
 * runs, and several of that request's own rules become false purely BECAUSE
 * the first call succeeded — vendor.status flips draft -> active, a
 * free-trial grant makes the "phone already used a trial" check trip on
 * itself, and a per-salesman-month count now includes the row just created.
 * A retried request would then fail validation for the wrong reason: not
 * because it's invalid, but because it already succeeded. Intercepting here,
 * before the FormRequest is ever resolved, means a genuine replay never
 * touches that validation at all — it just gets the original result back.
 */
class HandleIdempotentSubscription
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

        $existing = $this->subscriptions->findByIdempotencyKey($key);

        if ($existing !== null) {
            // No temporary_password here — it is shown once, on the
            // original response, and never stored in plaintext to hand
            // back on a replay.
            return ApiResponse::success([
                'subscription' => new SubscriptionResource(
                    $existing->load(['plan', 'items', 'payments', 'commission'])
                ),
            ]);
        }

        return $next($request);
    }
}
