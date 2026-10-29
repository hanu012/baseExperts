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
 * GET /api/customers/me/reviews — the customer's own reviews
 * (Profile → My reviews).
 */
class CustomerReviewIndexEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithUser(): array
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $user->id]);

        return [$user, $customer];
    }

    private function reviewBy(Customer $customer, array $overrides = []): Review
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
        $subcategory = Subcategory::factory()->for(Category::factory()->create())->create(['name' => 'AC repair']);

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);

        return Review::create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'rating' => 4,
            'comment' => 'Good work',
        ], $overrides));
    }

    public function test_it_lists_only_the_callers_own_reviews_with_vendor_and_service(): void
    {
        [$user, $customer] = $this->customerWithUser();
        $own = $this->reviewBy($customer, ['vendor_reply' => 'Thanks!', 'replied_at' => now()]);

        [, $otherCustomer] = $this->customerWithUser();
        $this->reviewBy($otherCustomer);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/me/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $row = $response->json('data.0');
        $this->assertSame($own->id, $row['id']);
        $this->assertSame('Cool Air Services', $row['vendor_name']);
        $this->assertSame('AC repair', $row['subcategory_name']);
        $this->assertSame(4, $row['rating']);
        $this->assertSame('Thanks!', $row['vendor_reply']);
        $this->assertNotNull($response->json('meta'));
    }

    public function test_a_hidden_review_is_still_listed_without_the_moderation_note(): void
    {
        [$user, $customer] = $this->customerWithUser();
        $this->reviewBy($customer, ['is_hidden' => true, 'hidden_reason' => 'Suspected fake']);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/me/reviews')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($row['is_hidden']);
        $this->assertArrayNotHasKey('hidden_reason', $row);
    }

    public function test_a_vendor_cannot_use_it(): void
    {
        $vendorUser = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        $this->actingAs($vendorUser, 'sanctum')
            ->getJson('/api/customers/me/reviews')
            ->assertForbidden();
    }
}
