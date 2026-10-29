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
 * POST /api/reviews + PATCH /api/reviews/{review} (SPEC section 9, task
 * 5.5) — gated on a matching lead within 30 days, one review per lead
 * (DB-enforced via reviews.lead_id being unique), 24-hour edit window.
 */
class ReviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): array
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

    private function subcategory(): Subcategory
    {
        return Subcategory::factory()->for(Category::factory()->create())->create();
    }

    private function leadFor(Customer $customer, Vendor $vendor, ?\DateTimeInterface $createdAt = null): Lead
    {
        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $this->subcategory()->id,
            'channel' => 'call',
        ]);

        if ($createdAt !== null) {
            $lead->forceFill(['created_at' => $createdAt])->save();
        }

        return $lead;
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_a_customer_can_review_a_vendor_they_recently_contacted(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $lead = $this->leadFor($customer, $vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5, 'comment' => 'Great service'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Great service')
            ->assertJsonPath('data.customer_name', $user->name);

        $this->assertDatabaseHas('reviews', [
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'rating' => 5,
        ]);
    }

    public function test_the_most_recent_eligible_lead_is_used_when_several_exist(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $older = $this->leadFor($customer, $vendor, now()->subDays(10));
        $newer = $this->leadFor($customer, $vendor, now()->subDay());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 4])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', ['lead_id' => $newer->id]);
        $this->assertDatabaseMissing('reviews', ['lead_id' => $older->id]);
    }

    public function test_a_second_review_attaches_to_a_different_still_eligible_lead(): void
    {
        // One customer, two distinct contacts with the same vendor —
        // SPEC's literal "one review per lead", not "per customer-vendor
        // pair".
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor, now()->subDays(5));
        $this->leadFor($customer, $vendor, now()->subDay());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])
            ->assertCreated();

        $this->assertSame(2, Review::where('vendor_id', $vendor->id)->count());
    }

    // ── Eligibility gating (SPEC section 9) ─────────────────────────────

    public function test_no_lead_at_all_is_rejected(): void
    {
        [$user] = $this->customer();
        $vendor = $this->vendor();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_a_lead_older_than_30_days_is_rejected(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor, now()->subDays(31));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_a_lead_exactly_at_the_30_day_boundary_is_accepted(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor, now()->subDays(30));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertCreated();
    }

    public function test_a_lead_already_reviewed_leaves_nothing_eligible(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    public function test_another_customers_lead_does_not_make_a_vendor_reviewable(): void
    {
        [$user] = $this->customer();
        [, $otherCustomer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($otherCustomer, $vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['vendor_id']]]);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_rating_out_of_range_is_rejected(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 6])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['rating']]]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $vendor = $this->vendor();

        $this->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])
            ->assertStatus(401);
    }

    // ── 24-hour edit window (SPEC section 4 item 9) ─────────────────────

    public function test_a_review_can_be_edited_within_24_hours(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $reviewId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])
            ->json('data.id');

        $this->travel(23)->hours();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/reviews/{$reviewId}", ['rating' => 5, 'comment' => 'Updated my mind'])
            ->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Updated my mind');

        $this->assertDatabaseHas('reviews', ['id' => $reviewId, 'rating' => 5]);
    }

    public function test_a_review_cannot_be_edited_after_24_hours(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $reviewId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])
            ->json('data.id');

        $this->travel(24 * 60 + 1)->minutes();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/reviews/{$reviewId}", ['rating' => 5])
            ->assertStatus(422);

        $this->assertDatabaseHas('reviews', ['id' => $reviewId, 'rating' => 3]);
    }

    public function test_a_different_customer_cannot_edit_someone_elses_review(): void
    {
        [$user, $customer] = $this->customer();
        [$otherUser] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $reviewId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])
            ->json('data.id');

        $this->actingAs($otherUser, 'sanctum')
            ->patchJson("/api/reviews/{$reviewId}", ['rating' => 1])
            ->assertStatus(404);

        $this->assertDatabaseHas('reviews', ['id' => $reviewId, 'rating' => 3]);
    }

    // ── Rating aggregate (SPEC section 4 item 5 / task 5.3's dormant tier)

    public function test_creating_a_review_recalculates_the_vendors_rating_aggregate(): void
    {
        [$user, $customer] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customer, $vendor);

        $this->assertSame(0, $vendor->fresh()->rating_count);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 4])
            ->assertCreated();

        $vendor->refresh();
        $this->assertSame(1, $vendor->rating_count);
        $this->assertSame(4.0, $vendor->rating_avg);
    }

    public function test_two_reviews_average_correctly(): void
    {
        [$userA, $customerA] = $this->customer();
        [$userB, $customerB] = $this->customer();
        $vendor = $this->vendor();
        $this->leadFor($customerA, $vendor);
        $this->leadFor($customerB, $vendor);

        $this->actingAs($userA, 'sanctum')->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 5])->assertCreated();
        $this->actingAs($userB, 'sanctum')->postJson('/api/reviews', ['vendor_id' => $vendor->id, 'rating' => 3])->assertCreated();

        $vendor->refresh();
        $this->assertSame(2, $vendor->rating_count);
        $this->assertSame(4.0, $vendor->rating_avg);
    }
}
