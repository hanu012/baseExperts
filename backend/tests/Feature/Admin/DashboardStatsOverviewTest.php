<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin Dashboard (SPEC section 5 item 1) — eight real counts/sums.
 * No Livewire rendering here: getStats() is called directly via
 * reflection since it's protected, which is both simpler and more
 * robust than scraping numbers out of rendered HTML.
 */
class DashboardStatsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function statsByLabel(): array
    {
        $widget = new DashboardStatsOverview();
        $reflection = new \ReflectionMethod($widget, 'getStats');
        $reflection->setAccessible(true);

        $stats = [];
        foreach ($reflection->invoke($widget) as $stat) {
            $stats[$stat->getLabel()] = $stat->getValue();
        }

        return $stats;
    }

    private function vendor(array $overrides = []): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ], $overrides));
    }

    private function activeSubscription(Vendor $vendor, array $overrides = []): Subscription
    {
        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        return Subscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }

    // ── Simple totals ────────────────────────────────────────────────────

    public function test_vendor_salesman_customer_counts_are_exact(): void
    {
        $this->vendor();
        $this->vendor();
        Salesman::create([
            'user_id' => User::factory()->role(UserRole::Salesman)->create()->id,
            'employee_code' => 'EMP-1',
            'phone' => '9900000001',
        ]);
        Customer::create(['user_id' => User::factory()->role(UserRole::Customer)->create()->id]);
        Customer::create(['user_id' => User::factory()->role(UserRole::Customer)->create()->id]);
        Customer::create(['user_id' => User::factory()->role(UserRole::Customer)->create()->id]);

        $stats = $this->statsByLabel();

        $this->assertSame(2, $stats['Vendors']);
        $this->assertSame(1, $stats['Salesmen']);
        $this->assertSame(3, $stats['Customers']);
    }

    public function test_a_soft_deleted_vendor_is_not_counted(): void
    {
        $vendor = $this->vendor();
        $vendor->delete();

        $this->assertSame(0, $this->statsByLabel()['Vendors']);
    }

    public function test_service_and_subservice_counts_map_to_categories_and_subcategories(): void
    {
        $category = Category::factory()->create();
        Category::factory()->create();
        Subcategory::factory()->for($category)->create();
        Subcategory::factory()->for($category)->create();
        Subcategory::factory()->for($category)->create();

        $stats = $this->statsByLabel();

        $this->assertSame(2, $stats['Services']);
        $this->assertSame(3, $stats['Subservices']);
    }

    // ── Subscriptions expiring in 30 days ───────────────────────────────

    public function test_expiring_count_includes_only_active_subscriptions_ending_within_30_days(): void
    {
        // In window, active — counts.
        $this->activeSubscription($this->vendor(), ['end_date' => now()->addDays(10)]);

        // Active but far in the future — does not count.
        $this->activeSubscription($this->vendor(), ['end_date' => now()->addDays(200)]);

        // In window but expired — does not count.
        $this->activeSubscription($this->vendor(), [
            'end_date' => now()->addDays(5),
            'status' => 'expired',
        ]);

        // In window but superseded (task 4.7's change-plan) — does not
        // count. Mirrors changePlan()'s own atomic update: superseded
        // rows have their end_date rewound to the day they were
        // replaced, not left in the future.
        $this->activeSubscription($this->vendor(), [
            'end_date' => now()->subDays(1),
            'status' => 'superseded',
        ]);

        $this->assertSame(1, $this->statsByLabel()['Expiring in 30 days']);
    }

    // ── Pending verification ────────────────────────────────────────────

    public function test_pending_verification_count_matches_the_scope(): void
    {
        $this->vendor(['status' => 'pending_verification']);
        $this->vendor(['status' => 'pending_verification']);
        $this->vendor(['status' => 'active']);

        $this->assertSame(2, $this->statsByLabel()['Pending verification']);
        $this->assertSame(
            Vendor::pendingVerification()->count(),
            $this->statsByLabel()['Pending verification']
        );
    }

    // ── Leads this week ──────────────────────────────────────────────────

    public function test_leads_this_week_excludes_leads_from_last_week(): void
    {
        $vendor = $this->vendor();
        $customerUser = User::factory()->role(UserRole::Customer)->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->create();

        $thisWeek = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);
        $thisWeek->forceFill(['created_at' => now()->startOfWeek()->addHours(2)])->save();

        $lastWeek = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'whatsapp',
        ]);
        $lastWeek->forceFill(['created_at' => now()->subWeek()])->save();

        $this->assertSame(1, $this->statsByLabel()['Leads this week']);
    }

    // ── Revenue this month ───────────────────────────────────────────────

    public function test_revenue_this_month_sums_payments_created_this_calendar_month(): void
    {
        $vendor = $this->vendor();
        $subscription = $this->activeSubscription($vendor);

        $thisMonth = Payment::create([
            'subscription_id' => $subscription->id,
            'amount_paise' => 99_900,
            'mode' => 'online',
            'status' => 'captured',
            'admin_verified_at' => now(),
        ]);
        $thisMonth->forceFill(['created_at' => now()->startOfMonth()->addDay()])->save();

        // A legitimate $0 change-plan payment (task 4.7 — a downgrade
        // credit fully covering the new price) must still be summed
        // correctly, contributing exactly nothing.
        $zeroThisMonth = Payment::create([
            'subscription_id' => $subscription->id,
            'amount_paise' => 0,
            'mode' => 'online',
            'status' => 'captured',
            'admin_verified_at' => now(),
        ]);
        $zeroThisMonth->forceFill(['created_at' => now()->startOfMonth()->addDays(2)])->save();

        $lastMonth = Payment::create([
            'subscription_id' => $subscription->id,
            'amount_paise' => 50_000,
            'mode' => 'cash',
            'status' => 'captured',
        ]);
        $lastMonth->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

        $stats = $this->statsByLabel();

        // 99_900 paise = ₹999.00 — last month's 50_000 paise must be excluded.
        $this->assertSame('₹999.00', $stats['Revenue this month']);
    }

    public function test_revenue_this_month_is_zero_currency_when_there_are_no_payments(): void
    {
        $this->assertSame('₹0.00', $this->statsByLabel()['Revenue this month']);
    }
}
