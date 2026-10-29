<?php

namespace App\Services\Payments;

use App\Support\RazorpayCredentials;
use Illuminate\Support\Facades\Http;

/**
 * The two Razorpay calls this platform makes: create a Payment Link, and
 * verify a webhook's signature.
 *
 * Plain HTTPS through Laravel's client rather than Razorpay's PHP SDK —
 * two endpoints do not justify a dependency (CLAUDE.md: no gateway SDK),
 * and Http::fake() makes both testable without a network.
 *
 * Docs: https://razorpay.com/docs/api/payments/payment-links/
 */
class RazorpayClient
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function isConfigured(): bool
    {
        return RazorpayCredentials::keyId() !== null
            && RazorpayCredentials::keySecret() !== null
            && RazorpayCredentials::webhookSecret() !== null;
    }

    /**
     * @param  string  $referenceId  ours, unique per link (Razorpay rejects a
     *                               reused one), max 40 characters
     * @return array{id: string, short_url: string, expire_by: int}
     *
     * @throws PaymentGatewayException
     */
    public function createPaymentLink(
        int $amountPaise,
        string $referenceId,
        string $description,
        string $customerName,
        ?string $customerPhone,
    ): array {
        $expireBy = now()->addMinutes(max(16, (int) config('services.razorpay.link_expiry_minutes', 1440)))->timestamp;

        $customer = array_filter([
            'name' => $customerName,
            'contact' => $this->e164($customerPhone),
        ]);

        try {
            $response = Http::withBasicAuth(
                (string) RazorpayCredentials::keyId(),
                (string) RazorpayCredentials::keySecret(),
            )
                ->acceptJson()
                ->timeout(15)
                ->post(self::BASE_URL.'/payment_links', [
                    'amount' => $amountPaise,
                    'currency' => 'INR',
                    'accept_partial' => false,
                    'reference_id' => $referenceId,
                    'description' => mb_substr($description, 0, 2048),
                    'customer' => $customer,
                    // SMS only. The platform sends no email (CLAUDE.md), and
                    // Razorpay emailing on our behalf would be the same thing.
                    'notify' => ['sms' => isset($customer['contact']), 'email' => false],
                    'reminder_enable' => true,
                    'expire_by' => $expireBy,
                ]);
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('Could not reach Razorpay: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful() || ! is_string($response->json('id')) || ! is_string($response->json('short_url'))) {
            throw new PaymentGatewayException(
                'Razorpay refused the payment link: '.($response->json('error.description') ?? $response->status())
            );
        }

        return [
            'id' => $response->json('id'),
            'short_url' => $response->json('short_url'),
            'expire_by' => (int) ($response->json('expire_by') ?? $expireBy),
        ];
    }

    /**
     * Stops a link being paid — used when a newer sale replaces an unpaid
     * one, so a vendor can never pay twice. A link that is already
     * cancelled or expired counts as done.
     *
     * @throws PaymentGatewayException
     */
    public function cancelPaymentLink(string $linkId): void
    {
        try {
            $response = Http::withBasicAuth(
                (string) RazorpayCredentials::keyId(),
                (string) RazorpayCredentials::keySecret(),
            )
                ->acceptJson()
                ->timeout(15)
                ->post(self::BASE_URL.'/payment_links/'.urlencode($linkId).'/cancel');
        } catch (\Throwable $e) {
            throw new PaymentGatewayException('Could not reach Razorpay: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        $description = strtolower((string) $response->json('error.description'));

        if (str_contains($description, 'cancelled') || str_contains($description, 'expired')) {
            return;
        }

        // Including "already paid": refusing here keeps the new sale from
        // going ahead on top of money that has just arrived.
        throw new PaymentGatewayException('Razorpay could not cancel the earlier payment link: '.($description ?: $response->status()));
    }

    /**
     * X-Razorpay-Signature is an HMAC-SHA256 of the raw request body with
     * the webhook secret. Compared in constant time.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) RazorpayCredentials::webhookSecret();

        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /** Vendor phones are stored as 10 digits; Razorpay wants +91XXXXXXXXXX. */
    private function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+91'.$digits;
        }

        return '+'.$digits;
    }
}
