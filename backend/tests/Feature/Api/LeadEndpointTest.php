<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/leads (SPEC section 4 item 7, task 5.4) — the write the
 * vendor-detail screen's Call/WhatsApp buttons must complete and confirm
 * before opening the dialer/WhatsApp intent. See Lead's own docblock for
 * why the FK-existence-only validation (no vendor-covers-subcategory
 * re-check) is deliberate.
 */
class LeadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(): User
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        Customer::create(['user_id' => $user->id]);

        return $user->fresh();
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

    private function subcategory(): Subcategory
    {
        $category = Category::factory()->create();

        return Subcategory::factory()->for($category)->create();
    }

    public function test_a_customer_can_record_a_call_lead(): void
    {
        $user = $this->customerUser();
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();
        $zone = Zone::factory()->active()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'zone_id' => $zone->id,
                'channel' => 'call',
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'created_at']]);

        $customer = Customer::where('user_id', $user->id)->firstOrFail();

        $this->assertDatabaseHas('leads', [
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'zone_id' => $zone->id,
            'channel' => 'call',
        ]);
    }

    public function test_a_customer_can_record_a_whatsapp_lead_with_no_zone(): void
    {
        $user = $this->customerUser();
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'whatsapp',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('leads', [
            'vendor_id' => $vendor->id,
            'zone_id' => null,
            'channel' => 'whatsapp',
        ]);
    }

    // ── "Never trust the client for who the customer is" ─────────────────

    public function test_customer_id_always_comes_from_the_token_never_the_client(): void
    {
        $user = $this->customerUser();
        $otherUser = $this->customerUser();
        $otherCustomer = Customer::where('user_id', $otherUser->id)->firstOrFail();
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', [
                'customer_id' => $otherCustomer->id, // ignored — no such field is accepted
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'call',
            ])
            ->assertCreated();

        $ownCustomer = Customer::where('user_id', $user->id)->firstOrFail();

        $this->assertDatabaseHas('leads', ['customer_id' => $ownCustomer->id]);
        $this->assertDatabaseMissing('leads', ['customer_id' => $otherCustomer->id]);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_missing_vendor_id_is_rejected(): void
    {
        $user = $this->customerUser();
        $subcategory = $this->subcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', ['subcategory_id' => $subcategory->id, 'channel' => 'call'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_a_nonexistent_vendor_id_is_rejected(): void
    {
        $user = $this->customerUser();
        $subcategory = $this->subcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => 999999,
                'subcategory_id' => $subcategory->id,
                'channel' => 'call',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_an_invalid_channel_is_rejected(): void
    {
        $user = $this->customerUser();
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'email',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['channel']]]);
    }

    // ── Role gating ──────────────────────────────────────────────────────

    public function test_a_vendor_cannot_call_this_endpoint(): void
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();

        $this->actingAs($vendorUser, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'call',
            ])
            ->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $vendor = $this->vendor();
        $subcategory = $this->subcategory();

        $this->postJson('/api/leads', [
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ])->assertStatus(401);
    }
}
