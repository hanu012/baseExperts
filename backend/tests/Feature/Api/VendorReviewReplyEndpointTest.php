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
 * POST /api/vendors/me/reviews/{review}/reply (SPEC section 4 item 9,
 * task 5.5) — a vendor's right of reply, scoped to reviews on their own
 * listing only.
 */
class VendorReviewReplyEndpointTest extends TestCase
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

    private function reviewOn(Vendor $vendor): Review
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->for(Category::factory()->create())->create();

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);

        return Review::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'rating' => 4,
            'comment' => 'Good work',
        ]);
    }

    public function test_a_vendor_can_reply_to_a_review_on_their_own_listing(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $review = $this->reviewOn($vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/reviews/{$review->id}/reply", ['reply' => 'Thank you!'])
            ->assertOk()
            ->assertJsonPath('data.vendor_reply', 'Thank you!');

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'vendor_reply' => 'Thank you!']);
        $this->assertNotNull($review->fresh()->replied_at);
    }

    public function test_a_vendor_cannot_reply_to_another_vendors_review(): void
    {
        [$user] = $this->vendorWithUser();
        [, $otherVendor] = $this->vendorWithUser();
        $review = $this->reviewOn($otherVendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/reviews/{$review->id}/reply", ['reply' => 'Thanks!'])
            ->assertStatus(404);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'vendor_reply' => null]);
    }

    public function test_a_missing_reply_is_rejected(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $review = $this->reviewOn($vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/reviews/{$review->id}/reply", [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['reply']]]);
    }

    public function test_an_overlong_reply_is_rejected(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $review = $this->reviewOn($vendor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/me/reviews/{$review->id}/reply", ['reply' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['reply']]]);
    }

    public function test_a_customer_cannot_call_this_endpoint(): void
    {
        [, $vendor] = $this->vendorWithUser();
        $review = $this->reviewOn($vendor);
        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);

        $this->actingAs($customerUser, 'sanctum')
            ->postJson("/api/vendors/me/reviews/{$review->id}/reply", ['reply' => 'Thanks!'])
            ->assertStatus(403);
    }
}
