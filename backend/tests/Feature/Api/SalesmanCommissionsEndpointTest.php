<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/salesmen/me/commissions (SPEC section 2.4) — pending/paid
 * commission totals only. Target-vs-achieved and cash-reconciliation are
 * explicitly out of scope (see SalesmanController::commissions()).
 */
class SalesmanCommissionsEndpointTest extends TestCase
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

    private function commissionFor(Salesman $salesman, int $amountPaise, string $status): Commission
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create();
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Vendor',
            'owner_name' => 'Owner',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
            'created_by_salesman_id' => $salesman->id,
        ]);

        $plan = Plan::factory()->create();

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman->id,
            'source' => 'salesman',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'price_paise' => 99_900,
            'duration_days' => 365,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Commission::create([
            'subscription_id' => $subscription->id,
            'salesman_id' => $salesman->id,
            'amount_paise' => $amountPaise,
            'rate_bps' => 1000,
            'status' => $status,
        ]);
    }

    public function test_it_requires_a_salesman_token(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create(['must_change_password' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/salesmen/me/commissions')
            ->assertStatus(403);
    }

    public function test_it_totals_pending_and_paid_separately(): void
    {
        $salesmanUser = $this->salesmanUser();
        $salesman = $salesmanUser->salesman;

        $this->commissionFor($salesman, 1_000, 'pending');
        $this->commissionFor($salesman, 2_000, 'pending');
        $this->commissionFor($salesman, 5_000, 'paid');

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/commissions')
            ->assertOk()
            ->assertJsonPath('data.pending_amount_paise', 3_000)
            ->assertJsonPath('data.pending_count', 2)
            ->assertJsonPath('data.paid_amount_paise', 5_000)
            ->assertJsonPath('data.paid_count', 1);
    }

    public function test_cancelled_commissions_are_excluded_from_both_totals(): void
    {
        $salesmanUser = $this->salesmanUser();
        $this->commissionFor($salesmanUser->salesman, 9_999, 'cancelled');

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/commissions')
            ->assertOk()
            ->assertJsonPath('data.pending_amount_paise', 0)
            ->assertJsonPath('data.paid_amount_paise', 0);
    }

    public function test_it_only_totals_this_salesmans_commissions(): void
    {
        $mine = $this->salesmanUser('EMP-MINE');
        $other = $this->salesmanUser('EMP-OTHER');

        $this->commissionFor($mine->salesman, 1_000, 'pending');
        $this->commissionFor($other->salesman, 50_000, 'pending');

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/salesmen/me/commissions')
            ->assertOk()
            ->assertJsonPath('data.pending_amount_paise', 1_000);
    }

    public function test_no_commissions_returns_zero_not_null(): void
    {
        $salesmanUser = $this->salesmanUser();

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/salesmen/me/commissions')
            ->assertOk()
            ->assertJsonPath('data.pending_amount_paise', 0)
            ->assertJsonPath('data.paid_amount_paise', 0)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.paid_count', 0);
    }
}
