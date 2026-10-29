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
 * GET /api/vendors/me/reviews (SPEC section 3 item 8, task 4.8) — the
 * vendor's own, unfiltered Reviews tab.
 */
class VendorReviewIndexEndpointTest extends TestCase
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

    private function reviewOn(Vendor $vendor, array $overrides = []): Review
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

        return Review::create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'rating' => 4,
            'comment' => 'Good work',
        ], $overrides));
    }

    public function test_it_lists_both_hidden_and_visible_reviews_on_the_callers_own_vendor(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        $visible = $this->reviewOn($vendor);
        $hidden = $this->reviewOn($vendor, ['is_hidden' => true, 'hidden_reason' => 'Suspected fake']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/reviews')
            ->assertOk();

        $response->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertTrue($ids->contains($hidden->id));

        $hiddenRow = collect($response->json('data'))->firstWhere('id', $hidden->id);
        $this->assertTrue($hiddenRow['is_hidden']);
        $visibleRow = collect($response->json('data'))->firstWhere('id', $visible->id);
        $this->assertFalse($visibleRow['is_hidden']);
    }

    public function test_it_excludes_another_vendors_reviews(): void
    {
        [$user, $vendor] = $this->vendorWithUser();
        [, $otherVendor] = $this->vendorWithUser();
        $this->reviewOn($vendor);
        $this->reviewOn($otherVendor);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/reviews')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
    }

    public function test_it_paginates(): void
    {
        [$user, $vendor] = $this->vendorWithUser();

        for ($i = 0; $i < 3; $i++) {
            $this->reviewOn($vendor);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/reviews?per_page=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
    }

    public function test_a_vendor_with_no_profile_gets_404(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vendors/me/reviews')
            ->assertStatus(404);
    }

    public function test_a_salesman_cannot_call_this_endpoint(): void
    {
        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);

        $this->actingAs($salesmanUser, 'sanctum')
            ->getJson('/api/vendors/me/reviews')
            ->assertStatus(403);
    }
}
