<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Commission;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/subscriptions/renew — a vendor who already had a plan buys
 * another, early while active, during grace, or after expiry.
 */
class RenewSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $salesmanUser;

    private Plan $plan;

    /** @var array{category_ids: array<int, int>, subcategory_ids: array<int, int>, zone_ids: array<int, int>} */
    private array $selection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        Salesman::create([
            'user_id' => $this->salesmanUser->id,
            'employee_code' => 'EMP-RN',
            'phone' => '9900000002',
            'commission_rate_bps' => 1000,
        ]);
        $this->salesmanUser->refresh();

        $this->plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $this->plan->id)->update(['max_categories' => 5, 'max_subcategories' => 15, 'max_zones' => 5]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zone = Zone::factory()->active()->create();

        $this->selection = [
            'category_ids' => [$category->id],
            'subcategory_ids' => [$subcategory->id],
            'zone_ids' => [$zone->id],
        ];
    }

    /**
     * A vendor of this salesman's, with one earlier subscription in the
     * given state.
     */
    private function vendorWithPlan(string $vendorStatus, string $subscriptionStatus, int $endsInDays, ?User $user = null): Vendor
    {
        $user ??= User::factory()->role(UserRole::Vendor)->create(['password' => Hash::make('vendor-own-password')]);

        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => '9812345678',
            'status' => $vendorStatus,
            'created_by_salesman_id' => $this->salesmanUser->salesman->id,
        ]);

        Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $this->plan->id,
            'salesman_id' => $this->salesmanUser->salesman->id,
            'source' => 'salesman',
            'status' => $subscriptionStatus,
            'start_date' => now()->startOfDay()->subDays(365 - $endsInDays),
            'end_date' => now()->startOfDay()->addDays($endsInDays),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return $vendor;
    }

    private function renew(User $actor, Vendor $vendor, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->postJson(
            '/api/subscriptions/renew',
            array_merge(['vendor_id' => $vendor->id, 'plan_id' => $this->plan->id, 'payment_mode' => 'cash'], $this->selection, $overrides),
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }

    private function latest(): Subscription
    {
        return Subscription::query()->latest('id')->firstOrFail();
    }

    public function test_an_expired_vendor_renews_and_goes_live_again(): void
    {
        $vendor = $this->vendorWithPlan('expired', 'expired', endsInDays: -10);
        $previousId = Subscription::sole()->id;

        $this->renew($this->salesmanUser, $vendor)
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.vendor.status', 'active');

        $renewal = $this->latest();
        $this->assertTrue($renewal->is_renewal);
        $this->assertSame($previousId, $renewal->previous_subscription_id);
        $this->assertSame(now()->toDateString(), $renewal->start_date->toDateString());
        $this->assertSame(now()->addDays(365)->toDateString(), $renewal->end_date->toDateString());
        $this->assertSame('expired', Subscription::find($previousId)->status);
        $this->assertSame(9_990, Commission::sole()->amount_paise);
        $this->assertSame('active', $vendor->fresh()->status);
    }

    public function test_renewing_early_keeps_every_remaining_day(): void
    {
        $vendor = $this->vendorWithPlan('active', 'active', endsInDays: 10);
        $current = Subscription::sole();

        $this->renew($this->salesmanUser, $vendor)->assertCreated();

        $this->assertSame(now()->addDays(10 + 365)->toDateString(), $this->latest()->end_date->toDateString());
        // One live plan at a time — search joins rely on it.
        $this->assertSame('superseded', $current->fresh()->status);
        $this->assertSame(1, Subscription::query()->whereIn('status', ['active', 'grace'])->count());
    }

    public function test_renewing_in_grace_starts_today(): void
    {
        $vendor = $this->vendorWithPlan('grace', 'grace', endsInDays: -3);

        $this->renew($this->salesmanUser, $vendor)->assertCreated();

        $this->assertSame(now()->addDays(365)->toDateString(), $this->latest()->end_date->toDateString());
        $this->assertSame('superseded', Subscription::query()->oldest('id')->first()->status);
        $this->assertSame('active', $vendor->fresh()->status);
    }

    public function test_renewal_never_touches_the_vendors_password(): void
    {
        $vendor = $this->vendorWithPlan('expired', 'expired', endsInDays: -10);

        $response = $this->renew($this->salesmanUser, $vendor)->assertCreated();

        $this->assertNull($response->json('data.temporary_password'));
        $this->assertTrue(Hash::check('vendor-own-password', $vendor->user->fresh()->password));
        $this->assertFalse((bool) $vendor->user->fresh()->must_change_password);
    }

    public function test_a_draft_vendor_subscribes_instead_of_renewing(): void
    {
        $vendor = Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'New Vendor',
            'owner_name' => 'A',
            'phone' => '9812345670',
            'status' => 'draft',
            'created_by_salesman_id' => $this->salesmanUser->salesman->id,
        ]);

        $this->renew($this->salesmanUser, $vendor)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);
    }

    public function test_a_free_trial_cannot_be_a_renewal(): void
    {
        $vendor = $this->vendorWithPlan('expired', 'expired', endsInDays: -10);

        $this->renew($this->salesmanUser, $vendor, ['payment_mode' => 'free', 'free_trial_days' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_mode']);
    }

    public function test_a_salesman_cannot_renew_another_salesmans_vendor(): void
    {
        $vendor = $this->vendorWithPlan('expired', 'expired', endsInDays: -10);
        $other = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        Salesman::create(['user_id' => $other->id, 'employee_code' => 'EMP-X', 'phone' => '9900000003']);

        $this->renew($other->fresh(), $vendor)->assertStatus(422);
    }

    public function test_a_vendor_renews_themselves_online(): void
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = $this->vendorWithPlan('expired', 'expired', endsInDays: -10, user: $vendorUser);

        $this->renew($vendorUser, $vendor, ['payment_mode' => 'online'])
            ->assertCreated()
            ->assertJsonPath('data.subscription.source', 'self')
            ->assertJsonPath('data.vendor.status', 'active');

        $this->renew($vendorUser, $vendor, ['payment_mode' => 'cash'])->assertStatus(422);
    }

    /** Razorpay on: the current plan keeps running until the renewal is paid. */
    public function test_a_razorpay_held_renewal_completes_with_the_days_carried(): void
    {
        Setting::updateOrCreate(['key' => 'razorpay_payment_links_enabled'], [
            'value' => 'true', 'type' => 'boolean', 'group' => 'payments', 'label' => 'Razorpay',
        ]);
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'rzp_test_secret',
            'services.razorpay.webhook_secret' => 'whsec',
        ]);
        Http::fake(['api.razorpay.com/v1/payment_links' => Http::response([
            'id' => 'plink_RENEW', 'short_url' => 'https://rzp.io/i/renew', 'expire_by' => now()->addDay()->timestamp,
        ])]);

        $vendor = $this->vendorWithPlan('active', 'active', endsInDays: 5);
        $current = Subscription::sole();

        $this->renew($this->salesmanUser, $vendor, ['payment_mode' => 'online'])
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'pending_payment');

        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame(0, Commission::count());

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_RENEW']],
            'payment' => ['entity' => ['id' => 'pay_RENEW']],
        ]]);
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
        ], $body)->assertOk();

        $renewal = $this->latest();
        $this->assertSame('active', $renewal->status);
        $this->assertSame(now()->addDays(5 + 365)->toDateString(), $renewal->end_date->toDateString());
        $this->assertSame('superseded', $current->fresh()->status);
        $this->assertSame(1, Commission::count());
    }
}
