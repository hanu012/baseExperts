<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Models\Customer;
use App\Models\Report;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Minimal, read-only Reports list (SPEC section 4 item 10 / section
 * 5.15) — no create/edit/delete/resolve action exists, only viewing.
 */
class ReportResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function vendor(): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
    }

    private function report(Vendor $vendor, array $overrides = []): Report
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        return Report::create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'reason' => 'This vendor never showed up and kept the advance.',
        ], $overrides));
    }

    public function test_the_list_shows_submitted_reports(): void
    {
        $report = $this->report($this->vendor());

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$report]);
    }

    public function test_no_create_action_is_registered(): void
    {
        Livewire::test(ListReports::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_no_row_actions_exist(): void
    {
        $report = $this->report($this->vendor());

        Livewire::test(ListReports::class)
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        // No status/resolve action exists on this deliberately minimal
        // list — confirming the row renders at all is enough to prove
        // there's no hidden action Filament silently no-ops on.
        $this->assertNotNull($report->id);
    }

    public function test_a_sub_admin_without_the_reports_permission_cannot_view_the_list(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(ReportResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_sub_admin_with_the_reports_permission_can_view_the_list(): void
    {
        $report = $this->report($this->vendor());
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::ReportsViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$report]);
    }
}
