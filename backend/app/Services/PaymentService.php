<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\RazorpayClient;

/**
 * Records a payment against a subscription (SPEC section 6.5).
 *
 * STATUS/VERIFICATION RULES (payments migration docblock): `cash` is
 * captured immediately (the salesman has the money in hand) but left
 * unverified — `admin_verified_at` stays null, which is exactly what drives
 * the SPEC section 5.9 cash-reconciliation queue and gates the matching
 * commission from moving to `paid`. `free` has nothing to reconcile, so it
 * is captured AND verified immediately.
 *
 * `online` has two behaviours, chosen by the admin setting
 * `razorpay_payment_links_enabled`:
 *  - OFF (default): the original placeholder — captured and verified on the
 *    spot, no money moves.
 *  - ON: a Razorpay Payment Link is created and the payment stays `pending`
 *    until Razorpay's webhook reports it paid (see
 *    SubscriptionService::completeOnlinePayment()). The callers check
 *    [isPending] to hold back activation.
 *
 * `amountPaise` is always explicit (task 4.7), not implicitly read from
 * `$subscription->price_paise` — an add-on is charged against an existing
 * subscription for its own price. Every caller states the amount.
 */
class PaymentService
{
    public function __construct(private readonly RazorpayClient $razorpay) {}

    public function payViaCash(Subscription $subscription, int $amountPaise, ?Salesman $collectedBy): Payment
    {
        return Payment::create([
            'subscription_id' => $subscription->id,
            'amount_paise' => $amountPaise,
            'mode' => 'cash',
            'status' => 'captured',
            'collected_by_salesman_id' => $collectedBy?->id,
            'admin_verified_at' => null,
        ]);
    }

    /**
     * @throws PaymentGatewayException when Razorpay is switched on but cannot
     *                                 create the link — the caller's
     *                                 transaction rolls back
     */
    public function payOnline(Subscription $subscription, int $amountPaise, ?SubscriptionAddon $addon = null): Payment
    {
        // Nothing to collect (a downgrade credited down to zero): no link —
        // Razorpay refuses a zero amount anyway.
        if (! $this->gatewayEnabled() || $amountPaise <= 0) {
            return Payment::create([
                'subscription_id' => $subscription->id,
                'subscription_addon_id' => $addon?->id,
                'amount_paise' => $amountPaise,
                'mode' => 'online',
                'status' => 'captured',
                'admin_verified_at' => now(),
            ]);
        }

        if (! $this->razorpay->isConfigured()) {
            // Switched on in Settings without keys in .env. Refusing is the
            // safe failure: falling back to the placeholder would hand out
            // paid plans for free.
            throw new PaymentGatewayException('Online payments are switched on but Razorpay keys are not configured.');
        }

        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'subscription_addon_id' => $addon?->id,
            'amount_paise' => $amountPaise,
            'mode' => 'online',
            'gateway' => 'razorpay',
            'status' => 'pending',
        ]);

        $vendor = $subscription->vendor;
        $planName = $subscription->plan?->name ?? 'plan';

        $link = $this->razorpay->createPaymentLink(
            amountPaise: $amountPaise,
            referenceId: 'payment_'.$payment->id,
            description: $addon === null
                ? "Service Marketplace — {$planName} subscription"
                : "Service Marketplace — {$addon->quantity} extra ".$addon->getAttribute('resource'),
            customerName: $vendor?->business_name ?? 'Vendor',
            customerPhone: $vendor?->phone,
        );

        $payment->forceFill([
            'gateway_order_id' => $link['id'],
            'payment_link_url' => $link['short_url'],
            'payment_link_expires_at' => now()->setTimestamp($link['expire_by']),
        ])->save();

        return $payment;
    }

    /**
     * A free-trial grant is still a real payment row — zero amount, nothing
     * to collect or reconcile, but the same audit trail every other mode
     * gets (SPEC section 5.14).
     */
    public function recordFreeTrial(Subscription $subscription): Payment
    {
        return Payment::create([
            'subscription_id' => $subscription->id,
            'amount_paise' => 0,
            'mode' => 'free',
            'status' => 'captured',
            'admin_verified_at' => now(),
        ]);
    }

    /**
     * Withdraws an unpaid online payment's link at the gateway. Placeholder
     * payments have no link and need nothing.
     *
     * @throws PaymentGatewayException
     */
    public function cancelOnline(Payment $payment): void
    {
        if ($payment->gateway === 'razorpay' && filled($payment->gateway_order_id)) {
            $this->razorpay->cancelPaymentLink($payment->gateway_order_id);
        }
    }

    /** The admin switch, Settings → Payments. Off unless set. */
    public function gatewayEnabled(): bool
    {
        return (bool) Setting::get('razorpay_payment_links_enabled', false);
    }
}
