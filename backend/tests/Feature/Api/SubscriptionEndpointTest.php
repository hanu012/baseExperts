<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/subscriptions (SPEC section 6) — salesman/admin-led subscribe
 * and vendor self-service (task 4.2). This is the endpoint SPEC section
 * 5.14 calls the highest-scrutiny audit case: a salesman granting
 * themselves commission or free-trial abuse.
 */
class SubscriptionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function salesmanUser(int $commissionRateBps = 1000, string $code = 'EMP-1'): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create([
            'must_change_password' => false,
        ]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => $code,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'commission_rate_bps' => $commissionRateBps,
        ]);

        return $user->fresh();
    }

    private function adminUser(): User
    {
        return User::factory()->role(UserRole::Admin)->create(['must_change_password' => false]);
    }

    private function draftVendor(?Salesman $salesman, string $phone = '9812345678'): Vendor
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => $phone,
            'status' => 'draft',
            'created_by_salesman_id' => $salesman?->id,
        ]);
    }

    private function planWithQuota(int $maxCategories = 5, int $maxSubcategories = 15, int $maxZones = 5): Plan
    {
        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update([
            'max_categories' => $maxCategories,
            'max_subcategories' => $maxSubcategories,
            'max_zones' => $maxZones,
        ]);

        return $plan->fresh(['quota']);
    }

    /**
     * @return array{0: Category, 1: Subcategory}
     */
    private function categoryWithSubcategory(): array
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

        return [$category, $subcategory];
    }

    private function leafZone(): Zone
    {
        return Zone::factory()->active()->create();
    }

    /**
     * @return array{0: Zone, 1: Zone} parent, child
     */
    private function zoneWithChild(): array
    {
        $parent = Zone::factory()->active()->create();
        $child = Zone::factory()->active()->create(['parent_id' => $parent->id]);

        return [$parent, $child];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor, Plan $plan, Category $category, Subcategory $subcategory, Zone $zone, array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'category_ids' => [$category->id],
            'subcategory_ids' => [$subcategory->id],
            'zone_ids' => [$zone->id],
            'payment_mode' => 'cash',
        ], $overrides);
    }

    private function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    /**
     * A self-registered vendor, with a known password, and their own
     * draft Vendor row (task 4.1's registration flow) — not
     * salesman/admin-created, so no created_by_salesman_id.
     */
    private function vendorSelfUser(string $password = 'correct-horse-battery', string $phone = '9812340001'): User
    {
        $user = User::factory()->role(UserRole::Vendor)->create([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ]);

        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => $user->name,
            'phone' => $phone,
            'status' => 'draft',
        ]);

        return $user->fresh();
    }

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_a_salesman_can_subscribe_a_vendor_with_cash(): void
    {
        $salesmanUser = $this->salesmanUser(commissionRateBps: 1200);
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $response = $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.source', 'salesman')
            ->assertJsonPath('data.vendor.status', 'active');

        $this->assertIsString($response->json('data.temporary_password'));
        $this->assertNotEmpty($response->json('data.temporary_password'));

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'status' => 'active']);

        $payment = Payment::sole();
        $this->assertSame('cash', $payment->mode);
        $this->assertSame('captured', $payment->status);
        $this->assertNull($payment->admin_verified_at);
        $this->assertSame(99_900, $payment->amount_paise);

        $commission = Commission::sole();
        $this->assertSame(1200, $commission->rate_bps);
        // 99900 * 1200 / 10000 = 11988.0 exactly.
        $this->assertSame(11_988, $commission->amount_paise);
        $this->assertSame('pending', $commission->status);

        $subscription = Subscription::sole();
        $this->assertSame(3, $subscription->items()->count());
    }

    public function test_online_payment_is_captured_and_verified_immediately(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        $payment = Payment::sole();
        $this->assertSame('online', $payment->mode);
        $this->assertSame('captured', $payment->status);
        $this->assertNotNull($payment->admin_verified_at);
    }

    public function test_a_free_trial_grant_has_zero_price_and_no_commission(): void
    {
        Setting::updateOrCreate(['key' => 'free_trial_max_days'], ['value' => '15', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'free_grants_per_salesman_month'], ['value' => '10', 'type' => 'integer']);

        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'payment_mode' => 'free',
                'free_trial_days' => 10,
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated()
            ->assertJsonPath('data.subscription.free_trial_days', 10)
            ->assertJsonPath('data.subscription.price_paise', 0);

        $payment = Payment::sole();
        $this->assertSame('free', $payment->mode);
        $this->assertSame(0, $payment->amount_paise);

        $this->assertSame(0, Commission::count());
    }

    public function test_admin_subscribing_creates_no_salesman_or_commission(): void
    {
        // Admin acting on a vendor that has no salesman at all.
        $vendor = $this->draftVendor(null);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($this->adminUser(), 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated()
            ->assertJsonPath('data.subscription.source', 'admin');

        $subscription = Subscription::sole();
        $this->assertNull($subscription->salesman_id);
        $this->assertSame(0, Commission::count());
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_a_missing_idempotency_key_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson('/api/subscriptions', $this->payload($vendor, $plan, $category, $subcategory, $zone))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Subscription::count());
    }

    public function test_a_malformed_idempotency_key_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => 'not-a-uuid'],
        )->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    /**
     * The critical case: a replay must NOT be revalidated against state the
     * first call itself changed (vendor.status flips to active). If it
     * were, this test would fail with a VALIDATION_FAILED on vendor_id
     * instead of returning the original subscription.
     */
    public function test_replaying_the_same_idempotency_key_returns_the_same_subscription(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();
        $key = $this->idempotencyKey();

        $this->actingAs($salesmanUser, 'sanctum');
        $payload = $this->payload($vendor, $plan, $category, $subcategory, $zone);

        $first = $this->postJson('/api/subscriptions', $payload, ['Idempotency-Key' => $key])
            ->assertCreated();

        $second = $this->postJson('/api/subscriptions', $payload, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            $first->json('data.subscription.id'),
            $second->json('data.subscription.id'),
        );
        $this->assertSame(1, Subscription::count());

        // Shown once, on the original response only.
        $this->assertNotEmpty($first->json('data.temporary_password'));
        $this->assertArrayNotHasKey('temporary_password', $second->json('data'));
    }

    // ── Quota ────────────────────────────────────────────────────────────

    public function test_exceeding_the_category_quota_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota(maxCategories: 1);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        [$category2, $subcategory2] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'category_ids' => [$category->id, $category2->id],
                'subcategory_ids' => [$subcategory->id, $subcategory2->id],
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['category_ids']]]);

        $this->assertSame(0, Subscription::count());
    }

    public function test_exceeding_the_zone_quota_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota(maxZones: 1);
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone1 = $this->leafZone();
        $zone2 = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone1, [
                'zone_ids' => [$zone1->id, $zone2->id],
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['zone_ids']]]);
    }

    // ── Server-side re-verification (never trust the client) ───────────────

    public function test_a_non_leaf_zone_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        [$parent, $child] = $this->zoneWithChild();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $parent, [
                'zone_ids' => [$parent->id],
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['zone_ids']]]);
    }

    public function test_a_subcategory_without_its_category_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        [$otherCategory, $otherSubcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                // otherSubcategory's category (otherCategory) is not selected.
                'subcategory_ids' => [$subcategory->id, $otherSubcategory->id],
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_ids']]]);
    }

    public function test_an_inactive_category_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        $category = Category::factory()->inactive()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['category_ids']]]);
    }

    // ── Ownership and vendor state ──────────────────────────────────────

    public function test_a_salesman_cannot_subscribe_another_salesmans_vendor(): void
    {
        $ownerSalesman = $this->salesmanUser(code: 'EMP-OWNER');
        $otherSalesman = $this->salesmanUser(code: 'EMP-OTHER');
        $vendor = $this->draftVendor($ownerSalesman->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($otherSalesman, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_an_admin_may_subscribe_any_vendor(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($this->adminUser(), 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();
    }

    public function test_a_vendor_not_in_draft_status_is_rejected(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $vendor->update(['status' => 'active']);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    // ── Free trial abuse guards (SPEC section 5.14: highest scrutiny) ────

    public function test_a_free_trial_over_the_admin_cap_is_rejected(): void
    {
        Setting::updateOrCreate(['key' => 'free_trial_max_days'], ['value' => '15', 'type' => 'integer']);

        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'payment_mode' => 'free',
                'free_trial_days' => 16,
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['free_trial_days']]]);
    }

    public function test_a_salesmans_monthly_free_grant_cap_is_enforced(): void
    {
        Setting::updateOrCreate(['key' => 'free_grants_per_salesman_month'], ['value' => '1', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'free_trial_max_days'], ['value' => '15', 'type' => 'integer']);

        $salesmanUser = $this->salesmanUser();
        $salesman = $salesmanUser->salesman;

        // A prior free grant this month already uses up the cap of 1.
        $priorVendor = $this->draftVendor($salesman, phone: '9800000001');
        Subscription::create([
            'vendor_id' => $priorVendor->id,
            'plan_id' => $this->planWithQuota()->id,
            'salesman_id' => $salesman->id,
            'source' => 'salesman',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(10),
            'price_paise' => 0,
            'duration_days' => 10,
            'free_trial_days' => 10,
            'idempotency_key' => $this->idempotencyKey(),
        ]);

        $vendor = $this->draftVendor($salesman, phone: '9800000002');
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'payment_mode' => 'free',
                'free_trial_days' => 5,
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['free_trial_days']]]);
    }

    public function test_a_phone_that_already_had_a_free_trial_is_rejected(): void
    {
        Setting::updateOrCreate(['key' => 'free_trial_max_days'], ['value' => '15', 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'free_grants_per_salesman_month'], ['value' => '10', 'type' => 'integer']);

        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman, phone: '9800000009');

        // An earlier free trial already used against this exact phone
        // number, on this same vendor row (vendors.phone is unique, so no
        // other vendor could ever hold it).
        Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $this->planWithQuota()->id,
            'salesman_id' => $salesmanUser->salesman->id,
            'source' => 'salesman',
            'status' => 'expired',
            'start_date' => now()->subDays(30),
            'end_date' => now()->subDays(20),
            'price_paise' => 0,
            'duration_days' => 10,
            'free_trial_days' => 10,
            'idempotency_key' => $this->idempotencyKey(),
        ]);
        $vendor->update(['status' => 'draft']);

        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'payment_mode' => 'free',
                'free_trial_days' => 5,
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['free_trial_days']]]);
    }

    // ── Vendor credentials ────────────────────────────────────────────────

    public function test_the_vendor_receives_a_working_temporary_password(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $response = $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        $tempPassword = $response->json('data.temporary_password');

        $vendorUser = $vendor->user()->first()->fresh();
        $this->assertTrue(Hash::check($tempPassword, $vendorUser->password));
        $this->assertTrue($vendorUser->must_change_password);
    }

    // ── Audit logging (SPEC section 5.14) ───────────────────────────────

    public function test_subscription_payment_and_commission_are_all_audited(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->draftVendor($salesmanUser->salesman);
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($salesmanUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        $subscription = Subscription::sole();
        $payment = Payment::sole();
        $commission = Commission::sole();

        $subscriptionLog = AuditLog::where('auditable_type', $subscription->getMorphClass())
            ->where('auditable_id', $subscription->id)->sole();
        $this->assertSame('created', $subscriptionLog->action);
        $this->assertSame($salesmanUser->id, $subscriptionLog->user_id);

        $paymentLog = AuditLog::where('auditable_type', $payment->getMorphClass())
            ->where('auditable_id', $payment->id)->sole();
        $this->assertSame($salesmanUser->id, $paymentLog->user_id);

        $commissionLog = AuditLog::where('auditable_type', $commission->getMorphClass())
            ->where('auditable_id', $commission->id)->sole();
        $this->assertSame($salesmanUser->id, $commissionLog->user_id);
        $this->assertSame($salesman = $salesmanUser->salesman->id, $commissionLog->new_values['salesman_id']);
    }

    // ── Vendor self-service (task 4.2) ──────────────────────────────────

    public function test_a_vendor_can_subscribe_themselves_online(): void
    {
        $vendorUser = $this->vendorSelfUser();
        $vendor = $vendorUser->vendor;
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($vendorUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated()
            ->assertJsonPath('data.subscription.source', 'self')
            // SPEC section 7: self-registered, so verification-queue bound,
            // not straight to active like the salesman/admin-led path.
            ->assertJsonPath('data.vendor.status', 'pending_verification');

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'status' => 'pending_verification']);
        $this->assertSame(0, Commission::count());
    }

    public function test_self_service_does_not_reset_the_vendors_own_password(): void
    {
        $vendorUser = $this->vendorSelfUser('my-own-chosen-password');
        $vendor = $vendorUser->vendor;
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($vendorUser, 'sanctum');

        $response = $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertCreated();

        // Never handed a temp password — they already have their own.
        $this->assertNull($response->json('data.temporary_password'));

        $refreshed = $vendorUser->fresh();
        $this->assertTrue(Hash::check('my-own-chosen-password', $refreshed->password));
        $this->assertFalse($refreshed->must_change_password);
    }

    public function test_self_service_cash_is_rejected(): void
    {
        $vendorUser = $this->vendorSelfUser();
        $vendor = $vendorUser->vendor;
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($vendorUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, ['payment_mode' => 'cash']),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_mode']]]);
    }

    public function test_self_service_free_trial_is_rejected(): void
    {
        $vendorUser = $this->vendorSelfUser();
        $vendor = $vendorUser->vendor;
        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($vendorUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($vendor, $plan, $category, $subcategory, $zone, [
                'payment_mode' => 'free',
                'free_trial_days' => 5,
            ]),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_mode']]]);
    }

    public function test_a_vendor_cannot_subscribe_someone_elses_account(): void
    {
        $vendorUser = $this->vendorSelfUser();
        $otherVendorUser = $this->vendorSelfUser(phone: '9812340002');
        $otherVendor = $otherVendorUser->vendor;

        $plan = $this->planWithQuota();
        [$category, $subcategory] = $this->categoryWithSubcategory();
        $zone = $this->leafZone();

        $this->actingAs($vendorUser, 'sanctum');

        $this->postJson(
            '/api/subscriptions',
            $this->payload($otherVendor, $plan, $category, $subcategory, $zone, ['payment_mode' => 'online']),
            ['Idempotency-Key' => $this->idempotencyKey()],
        )->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }
}
