<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/vendors/{vendor}/report (SPEC section 4 item 10 / section
 * 5.15) — minimal, see ReportController's own docblock.
 */
class ReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithUser(): array
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $user->id]);

        return [$user, $customer];
    }

    private function vendor(): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
    }

    public function test_a_customer_can_report_a_vendor(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->vendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/report", ['reason' => 'This vendor took an advance and never showed up.'])
            ->assertCreated();

        $this->assertDatabaseHas('reports', [
            'vendor_id' => $vendor->id,
            'reason' => 'This vendor took an advance and never showed up.',
        ]);
    }

    public function test_reason_is_required(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->vendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/report", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_second_report_against_the_same_vendor_does_not_duplicate(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->vendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/report", ['reason' => 'First complaint.'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/report", ['reason' => 'Second complaint, different wording.'])
            ->assertCreated();

        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('reports', ['reason' => 'First complaint.']);
    }

    public function test_a_customer_role_user_with_no_customer_row_gets_404(): void
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $vendor = $this->vendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/report", ['reason' => 'Complaint'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
