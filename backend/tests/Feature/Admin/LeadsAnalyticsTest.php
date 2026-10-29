<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Pages\LeadsAnalytics;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads & Call Analytics (SPEC section 5 item 11).
 */
class LeadsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
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

    private function subcategoryUnder(Category $category): Subcategory
    {
        return Subcategory::factory()->for($category)->create();
    }

    private function lead(array $overrides = []): Lead
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $vendor = $overrides['vendor'] ?? $this->vendor();
        $subcategory = $overrides['subcategory'] ?? $this->subcategoryUnder(Category::factory()->create());

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'zone_id' => $overrides['zone_id'] ?? null,
            'channel' => $overrides['channel'] ?? 'call',
        ]);

        if (isset($overrides['created_at'])) {
            $lead->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $lead;
    }

    // ── Basic listing ────────────────────────────────────────────────────

    public function test_the_page_lists_leads(): void
    {
        $lead = $this->lead();

        Livewire::test(LeadsAnalytics::class)
            ->assertCanSeeTableRecords([$lead]);
    }

    // ── Vendor filter ────────────────────────────────────────────────────

    public function test_the_vendor_filter_narrows_to_that_vendor_only(): void
    {
        $vendorA = $this->vendor();
        $vendorB = $this->vendor();

        $leadA = $this->lead(['vendor' => $vendorA]);
        $leadB = $this->lead(['vendor' => $vendorB]);

        Livewire::test(LeadsAnalytics::class)
            ->filterTable('vendor_id', $vendorA->id)
            ->assertCanSeeTableRecords([$leadA])
            ->assertCanNotSeeTableRecords([$leadB]);
    }

    // ── Zone filter, including a lead with no zone at all ───────────────

    public function test_the_zone_filter_narrows_correctly_and_excludes_a_null_zone_lead(): void
    {
        $zone = Zone::factory()->active()->withBoundary(ZoneFactory::square(23.0, 72.5))->create();

        $leadWithZone = $this->lead(['zone_id' => $zone->id]);
        $leadWithoutZone = $this->lead(['zone_id' => null]);

        Livewire::test(LeadsAnalytics::class)
            ->filterTable('zone_id', $zone->id)
            ->assertCanSeeTableRecords([$leadWithZone])
            ->assertCanNotSeeTableRecords([$leadWithoutZone]);
    }

    public function test_a_lead_with_no_zone_still_appears_unfiltered(): void
    {
        $leadWithoutZone = $this->lead(['zone_id' => null]);

        Livewire::test(LeadsAnalytics::class)
            ->assertCanSeeTableRecords([$leadWithoutZone]);
    }

    // ── Category filter — the load-bearing case: leads has no category_id,
    // only subcategory_id, so this must resolve through subcategory. ────

    public function test_the_category_filter_resolves_through_the_subcategory_chain(): void
    {
        $categoryA = Category::factory()->create(['name' => 'AC Service']);
        $categoryB = Category::factory()->create(['name' => 'Plumbing']);

        $subcategoryA = $this->subcategoryUnder($categoryA);
        $subcategoryB = $this->subcategoryUnder($categoryB);

        $leadA = $this->lead(['subcategory' => $subcategoryA]);
        $leadB = $this->lead(['subcategory' => $subcategoryB]);

        Livewire::test(LeadsAnalytics::class)
            ->filterTable('category_id', $categoryA->id)
            ->assertCanSeeTableRecords([$leadA])
            ->assertCanNotSeeTableRecords([$leadB]);
    }

    // ── Date range filter ────────────────────────────────────────────────

    public function test_the_date_range_filter_narrows_at_both_boundaries(): void
    {
        $inRange = $this->lead(['created_at' => now()->subDays(5)]);
        $tooOld = $this->lead(['created_at' => now()->subDays(20)]);
        $tooNew = $this->lead(['created_at' => now()->addDays(2)]);

        Livewire::test(LeadsAnalytics::class)
            ->filterTable('date_range', [
                'from' => now()->subDays(10)->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$tooOld, $tooNew]);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_leads_permission_cannot_access_the_page(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(LeadsAnalytics::getUrl())->assertForbidden();
    }

    public function test_a_sub_admin_with_the_leads_permission_can_access_the_page(): void
    {
        $lead = $this->lead();

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::LeadsViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        $this->get(LeadsAnalytics::getUrl())->assertOk();

        Livewire::test(LeadsAnalytics::class)
            ->assertCanSeeTableRecords([$lead]);
    }
}
