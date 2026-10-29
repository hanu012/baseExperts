<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/vendors/me/leads + POST .../request-review (SPEC section 3
 * items 7-8, task 4.8) — the vendor Leads tab and "Request a review".
 */
class VendorLeadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function vendorWithUser(): array
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        return [$user, $vendor];
    }

    private function leadOn(Vendor $vendor, array $overrides = []): Lead
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->for(Category::factory()->create())->create();

        return Lead::create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ], $overrides));
    }

    // ── Listing ──────────────────────────────────────────────────────────

    public function test_it_lists_only_the_callers_own_leads_newest_first(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        [, $otherVendor] = $this->vendorWithUser();

        $older = $this->leadOn($vendor);
        $older->forceFill(['created_at' => now()->subDays(2)])->save();
        $newer = $this->leadOn($vendor);
        $this->leadOn($otherVendor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/leads')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
        $response->assertJsonPath('data.0.customer_name', $newer->customer->user->name);
        $response->assertJsonPath('data.0.subcategory_name', $newer->subcategory->name);
        $response->assertJsonPath('data.0.channel', 'call');
    }

    public function test_the_response_paginates(): void
    {
        [$user, $vendor] = $this->vendorWithUser();

        for ($i = 0; $i < 3; $i++) {
            $this->leadOn($vendor);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/leads?per_page=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.per_page', 2);
    }

    public function test_has_review_reflects_whether_a_review_exists(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);
        Review::create([
            'lead_id' => $lead->id, 'customer_id' => $lead->customer_id, 'vendor_id' => $vendor->id, 'rating' => 5,
        ]);
        $this->leadOn($vendor); // no review

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/vendors/me/leads')->assertOk();

        $withReview = collect($response->json('data'))->firstWhere('id', $lead->id);
        $this->assertTrue($withReview['has_review']);
    }

    public function test_a_salesman_cannot_call_this_endpoint(): void
    {
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/vendors/me/leads')
            ->assertStatus(403);
    }

    // ── Request a review ────────────────────────────────────────────────

    public function test_a_vendor_can_request_a_review_on_an_eligible_lead(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertOk()
            ->assertJsonStructure(['data' => ['review_requested_at']]);

        $this->assertNotNull($lead->fresh()->review_requested_at);
    }

    public function test_a_second_request_on_the_same_lead_is_rejected(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor, ['review_requested_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_REQUESTED');
    }

    public function test_a_lead_that_already_has_a_review_is_rejected(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);
        Review::create([
            'lead_id' => $lead->id, 'customer_id' => $lead->customer_id, 'vendor_id' => $vendor->id, 'rating' => 5,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_REVIEWED');

        $this->assertNull($lead->fresh()->review_requested_at);
    }

    /**
     * Mirrors StoreReviewRequest's own 30-day eligibility window —
     * asking for a review the customer can no longer actually leave is
     * a dead end worth rejecting at request time, not discovered later
     * when the customer hits the same 422 themselves.
     */
    public function test_a_lead_older_than_30_days_is_rejected(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);
        $lead->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVIEW_WINDOW_EXPIRED');

        $this->assertNull($lead->fresh()->review_requested_at);
    }

    public function test_a_lead_just_inside_the_30_day_window_is_accepted(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);
        // Comfortably inside the window rather than the exact instant —
        // an exact now()->subDays(30) is racy here: this test's setup
        // and the controller's own now()->subDays(30) are computed a
        // request-roundtrip apart, so a razor's-edge boundary can land
        // on either side of a second purely from that elapsed time, not
        // from the actual 30-day rule being wrong.
        $lead->forceFill(['created_at' => now()->subDays(29)->subHours(23)])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertOk();
    }

    public function test_a_lead_belonging_to_another_vendor_is_not_found(): void
    {
        [$user] = $this->vendorWithUser();
        [, $otherVendor] = $this->vendorWithUser();
        $lead = $this->leadOn($otherVendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertStatus(404);
    }

    public function test_a_customer_cannot_call_this_endpoint(): void
    {
        [, $vendor] = $this->vendorWithUser();
        $lead = $this->leadOn($vendor);
        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);

        $this->actingAs($customerUser, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertStatus(403);
    }
}
