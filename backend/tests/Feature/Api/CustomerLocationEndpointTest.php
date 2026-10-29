<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/customers/me/location (SPEC section 4.2, task 4.6) — GPS point
 * or pincode fallback, resolved and persisted in one call.
 */
class CustomerLocationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(): User
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        Customer::create(['user_id' => $user->id]);

        return $user->fresh();
    }

    public function test_a_gps_point_inside_a_zone_persists_and_matches(): void
    {
        $zone = Zone::factory()->active()->withBoundary(ZoneFactory::square(23.0, 72.5))->create();
        $user = $this->customerUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', ['latitude' => 23.02, 'longitude' => 72.52])
            ->assertOk()
            ->assertJsonPath('data.zone.id', $zone->id)
            ->assertJsonPath('data.zone.name', $zone->name);

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]);
    }

    public function test_a_gps_point_matching_no_zone_still_persists_the_point(): void
    {
        $user = $this->customerUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', ['latitude' => 10.0, 'longitude' => 10.0])
            ->assertOk()
            ->assertJsonPath('data.zone', null);

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'latitude' => 10.0,
            'longitude' => 10.0,
        ]);
    }

    public function test_a_pincode_only_submission_persists_the_pincode(): void
    {
        $user = $this->customerUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', ['pincode' => '380001'])
            ->assertOk()
            ->assertJsonPath('data.zone', null);

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'pincode' => '380001',
        ]);
    }

    public function test_a_pincode_matching_an_active_zone_resolves_it(): void
    {
        $zone = Zone::factory()->active()->create(['pincode' => '380001']);
        $user = $this->customerUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', ['pincode' => '380001'])
            ->assertOk()
            ->assertJsonPath('data.zone.id', $zone->id);
    }

    public function test_a_payload_with_neither_point_nor_pincode_is_rejected(): void
    {
        $user = $this->customerUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['pincode']]]);
    }

    public function test_a_vendor_cannot_call_this_endpoint(): void
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        $this->actingAs($vendorUser, 'sanctum')
            ->postJson('/api/customers/me/location', ['pincode' => '380001'])
            ->assertStatus(403);
    }

    public function test_a_customer_with_no_profile_row_404s(): void
    {
        // A customer-role User with no Customer row — the same edge case
        // VendorController::me() guards against for vendors.
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/location', ['pincode' => '380001'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
