<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Customer}
     */
    private function customerWithUser(): array
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $user->id]);

        return [$user, $customer];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'home',
            'apartment_number' => 'B-402',
            'building_name' => 'Shivalik Heights',
            'street_address' => 'Satellite Rd, Jodhpur, Ahmedabad',
            'area' => 'Jodhpur',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380015',
            'latitude' => 23.0225,
            'longitude' => 72.5714,
        ], $overrides);
    }

    public function test_the_first_address_becomes_the_default(): void
    {
        [$user] = $this->customerWithUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.display_label', 'Home')
            ->assertJsonPath('data.latitude', 23.0225);
    }

    public function test_house_number_and_building_name_are_optional(): void
    {
        [$user] = $this->customerWithUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload([
                'apartment_number' => null,
                'building_name' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.apartment_number', null)
            ->assertJsonPath('data.building_name', null);
    }

    public function test_only_one_address_is_ever_default(): void
    {
        [$user, $customer] = $this->customerWithUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->assertCreated();
        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload(['label' => 'office', 'is_default' => true]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(1, $customer->addresses()->where('is_default', true)->count());
        $this->assertTrue(CustomerAddress::find($second)->is_default);
    }

    public function test_the_list_puts_the_default_first(): void
    {
        [$user] = $this->customerWithUser();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->json('data.id');
        $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload(['label' => 'office']))->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/me/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first);
    }

    public function test_other_needs_a_name_of_at_most_two_words(): void
    {
        [$user] = $this->customerWithUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload(['label' => 'other']))
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload(['label' => 'other', 'other_label' => 'my parents house']))
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload(['label' => 'other', 'other_label' => 'Parents House']))
            ->assertCreated()
            ->assertJsonPath('data.display_label', 'Parents House');
    }

    public function test_switching_away_from_other_clears_its_name(): void
    {
        [$user] = $this->customerWithUser();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload(['label' => 'other', 'other_label' => 'Gym']))
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/customers/me/addresses/{$id}", $this->payload(['label' => 'office', 'other_label' => 'Gym']))
            ->assertOk()
            ->assertJsonPath('data.other_label', null)
            ->assertJsonPath('data.display_label', 'Office');
    }

    public function test_deleting_the_default_promotes_another(): void
    {
        [$user, $customer] = $this->customerWithUser();

        $default = $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->json('data.id');
        $other = $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload(['label' => 'office']))->json('data.id');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/customers/me/addresses/{$default}")->assertOk();

        $this->assertSame(1, $customer->addresses()->count());
        $this->assertTrue(CustomerAddress::find($other)->is_default);
    }

    public function test_make_default_switches_the_default(): void
    {
        [$user] = $this->customerWithUser();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->json('data.id');
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload(['label' => 'office']))->json('data.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/customers/me/addresses/{$second}/default")->assertOk();

        $this->assertTrue(CustomerAddress::find($second)->is_default);
        $this->assertFalse(CustomerAddress::find($first)->is_default);
    }

    /** 404, not 403: another customer's ids cannot be probed. */
    public function test_another_customers_address_is_not_found(): void
    {
        [$owner] = $this->customerWithUser();
        [$intruder] = $this->customerWithUser();

        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->json('data.id');

        $this->actingAs($intruder, 'sanctum')->patchJson("/api/customers/me/addresses/{$id}", $this->payload())->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/customers/me/addresses/{$id}")->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->postJson("/api/customers/me/addresses/{$id}/default")->assertNotFound();

        $this->assertNotNull(CustomerAddress::find($id));
    }

    public function test_the_address_book_is_capped(): void
    {
        [$user] = $this->customerWithUser();

        for ($i = 0; $i < CustomerAddress::MAX_PER_CUSTOMER; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/customers/me/addresses', $this->payload())->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers/me/addresses', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ADDRESS_LIMIT_REACHED');
    }

    public function test_other_roles_cannot_use_it(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        $this->actingAs($vendor, 'sanctum')->getJson('/api/customers/me/addresses')->assertForbidden();
    }
}
