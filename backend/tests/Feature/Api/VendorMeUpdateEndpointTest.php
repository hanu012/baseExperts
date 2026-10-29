<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /api/vendors/me — the vendor editing their own business profile
 * (SPEC section 3.2).
 *
 * The interesting cases are the ones that must NOT work: the route takes
 * no vendor id, so the main risk is a crafted field reaching something
 * that decides entitlement or trust.
 */
class VendorMeUpdateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function vendorWithUser(array $overrides = []): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        return Vendor::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Cool Air & Co',
            'owner_name' => 'Asha R Patel',
            'phone' => '9811111222',
            'address' => '14 MG Road',
            'city' => 'Ahmedabad',
            'about' => 'AC installation and servicing since 2009.',
        ], $overrides);
    }

    public function test_a_vendor_can_update_their_own_profile(): void
    {
        $vendor = $this->vendorWithUser();

        $this->actingAs($vendor->user, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.vendor.business_name', 'Cool Air & Co')
            ->assertJsonPath('data.vendor.city', 'Ahmedabad')
            ->assertJsonPath('data.vendor.about', 'AC installation and servicing since 2009.');

        $vendor->refresh();

        $this->assertSame('Cool Air & Co', $vendor->business_name);
        $this->assertSame('9811111222', $vendor->phone);
        $this->assertSame('Ahmedabad', $vendor->city);
    }

    /**
     * The whole point of resolving the vendor from the token: there is no
     * id to tamper with, and a vendor_id in the body must be inert.
     */
    public function test_a_vendor_id_in_the_body_is_ignored(): void
    {
        $mine = $this->vendorWithUser();
        $theirs = $this->vendorWithUser(['business_name' => 'Someone Else']);

        $this->actingAs($mine->user, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload([
                'vendor_id' => $theirs->id,
                'id' => $theirs->id,
            ]))
            ->assertOk();

        $this->assertSame('Someone Else', $theirs->fresh()->business_name);
        $this->assertSame('Cool Air & Co', $mine->fresh()->business_name);
    }

    /**
     * A vendor must not be able to approve, un-suspend or verify
     * themselves through a profile edit.
     */
    public function test_status_and_trust_fields_cannot_be_set(): void
    {
        $vendor = $this->vendorWithUser([
            'status' => 'draft',
            'is_suspended' => true,
        ]);

        $this->actingAs($vendor->user, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload([
                'status' => 'active',
                'is_suspended' => false,
                'verified_at' => now()->toIso8601String(),
                'rating_avg' => 5,
            ]))
            ->assertOk();

        $vendor->refresh();

        $this->assertSame('draft', $vendor->status);
        $this->assertTrue((bool) $vendor->is_suspended);
        $this->assertNull($vendor->verified_at);
    }

    public function test_a_duplicate_phone_is_rejected(): void
    {
        $other = $this->vendorWithUser(['phone' => '9800000001']);
        $mine = $this->vendorWithUser();

        $this->actingAs($mine->user, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload(['phone' => $other->phone]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * Saving without changing the phone must not trip its own uniqueness
     * rule — the commonest edit of all is a one-word change elsewhere.
     */
    public function test_keeping_your_own_phone_is_not_a_duplicate(): void
    {
        $vendor = $this->vendorWithUser(['phone' => '9800000002']);

        $this->actingAs($vendor->user, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload(['phone' => '9800000002']))
            ->assertOk();
    }

    public function test_business_name_and_phone_are_required(): void
    {
        $vendor = $this->vendorWithUser();

        $this->actingAs($vendor->user, 'sanctum')
            ->patchJson('/api/vendors/me', ['business_name' => '', 'phone' => ''])
            ->assertStatus(422);
    }

    public function test_a_salesman_token_is_refused(): void
    {
        $salesman = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesman, 'sanctum')
            ->patchJson('/api/vendors/me', $this->payload())
            ->assertStatus(403);
    }

    public function test_it_requires_a_token(): void
    {
        $this->patchJson('/api/vendors/me', $this->payload())->assertStatus(401);
    }
}
