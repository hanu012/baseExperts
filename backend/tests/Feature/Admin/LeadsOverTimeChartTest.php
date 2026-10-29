<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Widgets\LeadsOverTimeChart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leads & Call Analytics' chart widget (SPEC section 5 item 11) —
 * day-by-day counts over a selectable window, gaps filled to zero
 * rather than skipped.
 */
class LeadsOverTimeChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    /**
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    private function dataFor(LeadsOverTimeChart $widget): array
    {
        $reflection = new \ReflectionMethod($widget, 'getData');
        $reflection->setAccessible(true);

        return $reflection->invoke($widget);
    }

    private function leadOn(\DateTimeInterface|string $createdAt): Lead
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create();
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $customerUser = User::factory()->role(UserRole::Customer)->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $subcategory = Subcategory::factory()->for(Category::factory()->create())->create();

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);
        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    public function test_a_day_with_no_leads_appears_as_zero_not_a_gap(): void
    {
        $this->leadOn(now()->subDays(6));
        $this->leadOn(now()->subDays(6));
        // Deliberately nothing on subDays(5) — the gap day under test.
        $this->leadOn(now()->subDays(4));

        $widget = new LeadsOverTimeChart();
        $widget->filter = '7';

        $data = $this->dataFor($widget);

        $this->assertCount(7, $data['labels']);
        $this->assertCount(7, $data['datasets'][0]['data']);

        $values = $data['datasets'][0]['data'];
        $this->assertSame(2, $values[0]);
        $this->assertSame(0, $values[1]);
        $this->assertSame(1, $values[2]);
    }

    public function test_the_filter_changes_the_window_queried(): void
    {
        $this->leadOn(now()->subDays(2));
        $outsideSevenDays = $this->leadOn(now()->subDays(15));

        $sevenDayWidget = new LeadsOverTimeChart();
        $sevenDayWidget->filter = '7';
        $sevenDayData = $this->dataFor($sevenDayWidget);

        $this->assertSame(1, array_sum($sevenDayData['datasets'][0]['data']));

        $thirtyDayWidget = new LeadsOverTimeChart();
        $thirtyDayWidget->filter = '30';
        $thirtyDayData = $this->dataFor($thirtyDayWidget);

        $this->assertSame(2, array_sum($thirtyDayData['datasets'][0]['data']));
        $this->assertNotNull($outsideSevenDays->id);
    }

    public function test_defaults_to_30_days_when_no_filter_is_selected(): void
    {
        $this->leadOn(now()->subDays(20));

        $widget = new LeadsOverTimeChart();

        $data = $this->dataFor($widget);

        $this->assertCount(30, $data['labels']);
        $this->assertSame(1, array_sum($data['datasets'][0]['data']));
    }
}
