<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/salesmen/me/vendors (SPEC section 2.3) — My Vendors: name, plan,
 * days to expiry. Leads-this-month is deliberately absent (Phase 5).
 */
class SalesmanVendorsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function salesmanUser(string $code = 'EMP-1'): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create([
            'must_change_password' => false,
        ]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => $code,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);

        return $user->fresh();
    }

    private function vendor(?Salesman $salesman, string $name = 'Cool Air', string $status = 'draft'): Vendor
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => $name,
            'owner_name' => 'Owner',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => $status,
            'created_by_salesman_id' => $salesman?->id,
        ]);
    }

    private function subscriptionFor(Vendor $vendor, ?Salesman $salesman, string $planName, \DateTimeInterface $endDate, string $status = 'active'): Subscription
    {
        $plan = Plan::factory()->create(['name' => $planName]);

        return Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman?->id,
            'source' => 'salesman',
            'status' => $status,
            'start_date' => now()->subDays(30),
            'end_date' => $endDate,
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_it_requires_a_salesman_token(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create(['must_change_password' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertStatus(403);
    }

    public function test_it_only_returns_this_salesmans_vendors(): void
    {
        $mine = $this->salesmanUser('EMP-MINE');
        $other = $this->salesmanUser('EMP-OTHER');

        $this->vendor($mine->salesman, 'My Vendor');
        $this->vendor($other->salesman, 'Someone Elses Vendor');

        $response = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertOk();

        $names = array_column($response->json('data'), 'business_name');
        $this->assertSame(['My Vendor'], $names);
    }

    public function test_a_draft_vendor_with_no_subscription_has_null_plan_and_days(): void
    {
        $salesmanUser = $this->salesmanUser();
        $this->vendor($salesmanUser->salesman, 'Still Draft');

        $response = $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertOk();

        $response->assertJsonPath('data.0.plan_name', null)
            ->assertJsonPath('data.0.days_to_expiry', null);
    }

    public function test_an_active_subscription_shows_a_positive_days_to_expiry(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->vendor($salesmanUser->salesman, 'Active Vendor', 'active');
        $this->subscriptionFor($vendor, $salesmanUser->salesman, 'Gold', now()->addDays(10)->startOfDay());

        $response = $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertOk();

        $response->assertJsonPath('data.0.plan_name', 'Gold')
            ->assertJsonPath('data.0.days_to_expiry', 10);
    }

    public function test_an_expired_subscription_shows_a_negative_days_to_expiry(): void
    {
        $salesmanUser = $this->salesmanUser();
        $vendor = $this->vendor($salesmanUser->salesman, 'Expired Vendor', 'expired');
        $this->subscriptionFor(
            $vendor,
            $salesmanUser->salesman,
            'Silver',
            now()->subDays(4)->startOfDay(),
            'expired'
        );

        $response = $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertOk();

        $response->assertJsonPath('data.0.plan_name', 'Silver')
            ->assertJsonPath('data.0.days_to_expiry', -4);
    }

    public function test_no_leads_field_is_present(): void
    {
        $salesmanUser = $this->salesmanUser();
        $this->vendor($salesmanUser->salesman, 'Any Vendor');

        $response = $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/vendors')
            ->assertOk();

        $this->assertArrayNotHasKey('leads', $response->json('data.0'));
        $this->assertArrayNotHasKey('leads_this_month', $response->json('data.0'));
    }
}
