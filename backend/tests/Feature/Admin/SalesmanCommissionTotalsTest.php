<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Widgets\SalesmanCommissionTotals;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Commission & Payouts page's per-salesman totals widget (SPEC
 * section 5 item 9) — pending/paid shown side by side, isolated per
 * salesman.
 */
class SalesmanCommissionTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    private function salesman(string $name): Salesman
    {
        return Salesman::create([
            'user_id' => User::factory()->role(UserRole::Salesman)->create(['name' => $name])->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function commission(Salesman $salesman, int $amountPaise, string $status): Commission
    {
        $vendor = Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman->id,
            'source' => 'salesman',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Commission::create([
            'subscription_id' => $subscription->id,
            'salesman_id' => $salesman->id,
            'amount_paise' => $amountPaise,
            'rate_bps' => 1000,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    public function test_pending_and_paid_totals_are_correct_and_isolated_per_salesman(): void
    {
        $salesmanA = $this->salesman('Priya Sharma');
        $salesmanB = $this->salesman('Rahul Verma');

        // Salesman A: two pending (300 + 200 = 500), one paid (100).
        $this->commission($salesmanA, 30000, 'pending');
        $this->commission($salesmanA, 20000, 'pending');
        $this->commission($salesmanA, 10000, 'paid');

        // Salesman B: one pending (150), two paid (400 + 100 = 500).
        $this->commission($salesmanB, 15000, 'pending');
        $this->commission($salesmanB, 40000, 'paid');
        $this->commission($salesmanB, 10000, 'paid');

        $component = Livewire::test(SalesmanCommissionTotals::class);

        $component->assertSeeText(['Priya Sharma', '₹500.00', '₹100.00']);
        $component->assertSeeText(['Rahul Verma', '₹150.00', '₹500.00']);
    }

    public function test_a_salesman_with_zero_commissions_does_not_appear(): void
    {
        $this->salesman('No Sales Yet');
        $withSales = $this->salesman('Priya Sharma');
        $this->commission($withSales, 10000, 'pending');

        Livewire::test(SalesmanCommissionTotals::class)
            ->assertDontSeeText('No Sales Yet')
            ->assertSeeText('Priya Sharma');
    }

    public function test_cancelled_commissions_are_excluded_from_both_totals(): void
    {
        $salesman = $this->salesman('Priya Sharma');
        $this->commission($salesman, 10000, 'pending');
        $this->commission($salesman, 99999, 'cancelled');

        Livewire::test(SalesmanCommissionTotals::class)
            ->assertSeeText('₹100.00')
            ->assertDontSeeText('999.99');
    }
}
