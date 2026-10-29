<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Online payments through Razorpay Payment Links, behind the admin setting
 * `razorpay_payment_links_enabled`. Razorpay is faked throughout — no
 * network. The placeholder behaviour (setting off) stays covered by
 * SubscriptionEndpointTest::test_online_payment_is_captured_and_verified_immediately.
 */
class RazorpayPaymentLinksTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'rzp_test_secret',
            'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        Http::fake([
            'api.razorpay.com/v1/payment_links' => Http::response([
                'id' => 'plink_TEST123',
                'short_url' => 'https://rzp.io/i/test123',
                'status' => 'created',
                'expire_by' => now()->addDay()->timestamp,
            ]),
        ]);
    }

    private function enableRazorpay(bool $on = true): void
    {
        Setting::updateOrCreate(['key' => 'razorpay_payment_links_enabled'], [
            'value' => $on ? 'true' : 'false',
            'type' => 'boolean',
            'group' => 'payments',
            'label' => 'Online payments via Razorpay',
        ]);
    }

    private function salesmanUser(): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-RZ',
            'phone' => '9900000001',
            'commission_rate_bps' => 1000,
        ]);

        return $user->fresh();
    }

    private function draftVendor(?Salesman $salesman, ?User $user = null): Vendor
    {
        $user ??= User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => '9812345678',
            'status' => 'draft',
            'created_by_salesman_id' => $salesman?->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor): array
    {
        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update(['max_categories' => 5, 'max_subcategories' => 15, 'max_zones' => 5]);
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zone = Zone::factory()->active()->create();

        return [
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'category_ids' => [$category->id],
            'subcategory_ids' => [$subcategory->id],
            'zone_ids' => [$zone->id],
            'payment_mode' => 'online',
        ];
    }

    private function subscribe(User $actor, array $payload): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->postJson(
            '/api/subscriptions',
            $payload,
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }

    /**
     * Sends a webhook exactly as Razorpay does: raw JSON body, HMAC-SHA256
     * of that body in X-Razorpay-Signature.
     */
    private function webhook(string $event, string $linkId = 'plink_TEST123', ?string $signature = null): TestResponse
    {
        $body = json_encode([
            'event' => $event,
            'payload' => [
                'payment_link' => ['entity' => ['id' => $linkId, 'status' => 'paid']],
                'payment' => ['entity' => ['id' => 'pay_TEST999', 'status' => 'captured']],
            ],
        ]);

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }

    public function test_with_razorpay_on_an_online_sale_waits_for_payment(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);

        $this->subscribe($salesmanUser, $this->payload($vendor))
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'pending_payment')
            ->assertJsonPath('data.subscription.payment.status', 'pending')
            ->assertJsonPath('data.subscription.payment.payment_link_url', 'https://rzp.io/i/test123')
            ->assertJsonPath('data.vendor.status', 'draft');

        // Nothing goes live and nobody is paid commission before the money.
        $this->assertSame(0, Commission::count());

        $payment = Payment::sole();
        $this->assertSame('razorpay', $payment->gateway);
        $this->assertSame('plink_TEST123', $payment->gateway_order_id);

        // The server's amount, the vendor's phone in E.164, SMS only.
        Http::assertSent(fn (HttpRequest $request) => $request['amount'] === 99_900
            && $request['currency'] === 'INR'
            && $request['customer']['contact'] === '+919812345678'
            && $request['notify'] === ['sms' => true, 'email' => false]
            && $request['reference_id'] === 'payment_'.$payment->id);
    }

    public function test_the_paid_webhook_activates_everything_that_was_held(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $this->subscribe($salesmanUser, $this->payload($vendor))->assertCreated();

        $this->webhook('payment_link.paid')->assertOk()->assertJsonPath('data.handled', true);

        $subscription = Subscription::sole();
        $this->assertSame('active', $subscription->status);
        $this->assertSame(now()->toDateString(), $subscription->start_date->toDateString());
        $this->assertSame(now()->addDays(365)->toDateString(), $subscription->end_date->toDateString());

        $payment = Payment::sole();
        $this->assertSame('captured', $payment->status);
        $this->assertSame('pay_TEST999', $payment->gateway_payment_id);
        $this->assertNotNull($payment->admin_verified_at);

        $this->assertSame('active', $vendor->fresh()->status);
        // 99900 * 1000 / 10000.
        $this->assertSame(9_990, Commission::sole()->amount_paise);
    }

    public function test_a_repeated_webhook_changes_nothing_the_second_time(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $this->subscribe($salesmanUser, $this->payload($vendor))->assertCreated();

        $this->webhook('payment_link.paid')->assertOk();
        $this->webhook('payment_link.paid')->assertOk();

        $this->assertSame(1, Commission::count());
    }

    public function test_a_wrongly_signed_webhook_is_rejected_and_changes_nothing(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $this->subscribe($salesmanUser, $this->payload($vendor))->assertCreated();

        $this->webhook('payment_link.paid', signature: 'forged')->assertStatus(400);

        $this->assertSame('pending_payment', Subscription::sole()->status);
        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_an_expired_link_cancels_the_sale(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $this->subscribe($salesmanUser, $this->payload($vendor))->assertCreated();

        $this->webhook('payment_link.expired')->assertOk();

        $this->assertSame('cancelled', Subscription::sole()->status);
        $this->assertSame('failed', Payment::sole()->status);
        $this->assertSame(0, Commission::count());
        $this->assertSame('draft', $vendor->fresh()->status);
    }

    public function test_a_webhook_for_an_unknown_link_is_acknowledged_and_ignored(): void
    {
        $this->webhook('payment_link.paid', linkId: 'plink_SOMEONE_ELSE')
            ->assertOk()
            ->assertJsonPath('data.handled', false);
    }

    public function test_a_self_registered_vendor_goes_to_verification_once_paid(): void
    {
        $this->enableRazorpay();
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = $this->draftVendor(null, $vendorUser);

        $this->subscribe($vendorUser, $this->payload($vendor))->assertCreated();
        $this->webhook('payment_link.paid')->assertOk();

        $this->assertSame('pending_verification', $vendor->fresh()->status);
    }

    public function test_switched_on_without_keys_refuses_instead_of_giving_it_away(): void
    {
        $this->enableRazorpay();
        config(['services.razorpay.key_secret' => null]);
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);

        $this->subscribe($salesmanUser, $this->payload($vendor))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYMENT_GATEWAY_UNAVAILABLE');

        // Rolled back whole — the salesman can retry or take cash.
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_razorpay_refusing_the_link_rolls_the_sale_back(): void
    {
        $this->enableRazorpay();
        Http::fake([
            'api.razorpay.com/*' => Http::response(['error' => ['description' => 'Authentication failed']], 401),
        ]);
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);

        $this->subscribe($salesmanUser, $this->payload($vendor))->assertStatus(503);

        $this->assertSame(0, Subscription::count());
        $this->assertSame('draft', $vendor->fresh()->status);
    }

    /**
     * One live link per vendor: a new sale (here the salesman switching to
     * cash) withdraws the unpaid one at Razorpay and cancels it here.
     */
    public function test_a_new_sale_withdraws_an_unpaid_link(): void
    {
        $this->enableRazorpay();
        Http::fake([
            'api.razorpay.com/v1/payment_links/plink_TEST123/cancel' => Http::response(['id' => 'plink_TEST123', 'status' => 'cancelled']),
            'api.razorpay.com/v1/payment_links' => Http::response([
                'id' => 'plink_TEST123',
                'short_url' => 'https://rzp.io/i/test123',
                'expire_by' => now()->addDay()->timestamp,
            ]),
        ]);
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $payload = $this->payload($vendor);

        $this->subscribe($salesmanUser, $payload)->assertCreated();
        $this->subscribe($salesmanUser, array_merge($payload, ['payment_mode' => 'cash']))->assertCreated();

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/payment_links/plink_TEST123/cancel'));

        $this->assertSame(['active', 'cancelled'], Subscription::query()->orderByDesc('id')->pluck('status')->all());
        $this->assertSame('failed', Payment::query()->where('mode', 'online')->sole()->status);
        $this->assertSame('active', $vendor->fresh()->status);
    }

    /**
     * Paid in the instant after the link was withdrawn: the money is
     * recorded, nothing is activated on top of the newer sale.
     */
    public function test_a_payment_on_a_withdrawn_link_is_recorded_not_activated(): void
    {
        $this->enableRazorpay();
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $this->subscribe($salesmanUser, $this->payload($vendor))->assertCreated();
        $this->webhook('payment_link.expired')->assertOk();

        $this->webhook('payment_link.paid')->assertOk();

        $payment = Payment::sole();
        $this->assertSame('captured', $payment->status);
        $this->assertSame('pay_TEST999', $payment->gateway_payment_id);
        $this->assertSame('cancelled', Subscription::sole()->status);
        $this->assertSame(0, Commission::count());
    }

    public function test_the_vendor_profile_reports_a_pending_payment(): void
    {
        $this->enableRazorpay();
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = $this->draftVendor(null, $vendorUser);
        $this->subscribe($vendorUser, $this->payload($vendor))->assertCreated();

        $this->actingAs($vendorUser, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertOk()
            ->assertJsonPath('data.vendor.has_active_subscription', false)
            ->assertJsonPath('data.vendor.pending_payment.amount_paise', 99_900);

        $this->webhook('payment_link.paid')->assertOk();

        $this->actingAs($vendorUser, 'sanctum')
            ->getJson('/api/vendors/me')
            ->assertJsonPath('data.vendor.has_active_subscription', true)
            ->assertJsonPath('data.vendor.pending_payment', null);
    }

    public function test_with_razorpay_off_online_stays_the_placeholder(): void
    {
        $this->enableRazorpay(false);
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);

        $this->subscribe($salesmanUser, $this->payload($vendor))
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.payment.status', 'captured')
            ->assertJsonPath('data.subscription.payment.payment_link_url', null);

        Http::assertNothingSent();
    }
}
