<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Media;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/vendors/{vendor}/detail (SPEC section 4 item 6, task 5.4) —
 * the screen the search-results list (task 5.3) leads into. Public, no
 * auth: same visibility floor as vendor search (active vendor + active
 * unexpired subscription), plus approved-only media and an optional
 * distance calc.
 */
class VendorDetailEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function subcategory(): Subcategory
    {
        $category = Category::factory()->create();

        return Subcategory::factory()->for($category)->create();
    }

    /**
     * @return array{0: Vendor, 1: Subscription, 2: Subcategory}
     */
    private function activeVendorWithSubscription(): array
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'address' => '12 MG Road',
            'latitude' => 23.03,
            'longitude' => 72.55,
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $subcategory = $this->subcategory();

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$vendor, $subscription, $subcategory];
    }

    public function test_it_returns_services_from_the_active_subscription(): void
    {
        [$vendor, , $subcategory] = $this->activeVendorWithSubscription();

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.id', $vendor->id)
            ->assertJsonPath('data.business_name', 'Cool Air Services')
            ->assertJsonPath('data.phone', '9812345678')
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.subcategory_id', $subcategory->id)
            ->assertJsonPath('data.services.0.category_id', $subcategory->category_id);
    }

    public function test_a_deactivated_subcategory_is_not_listed_as_a_service(): void
    {
        [$vendor, , $subcategory] = $this->activeVendorWithSubscription();
        $subcategory->update(['is_active' => false]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonCount(0, 'data.services');
    }

    public function test_a_subcategory_under_a_deactivated_category_is_not_listed(): void
    {
        [$vendor, , $subcategory] = $this->activeVendorWithSubscription();
        $subcategory->category->update(['is_active' => false]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonCount(0, 'data.services');
    }

    public function test_a_vendor_with_no_active_subscription_is_not_found(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345678',
            'status' => 'active',
        ]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")->assertStatus(404);
    }

    public function test_a_suspended_vendor_is_not_found(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();
        $vendor->update(['is_suspended' => true]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")->assertStatus(404);
    }

    // ── Grace window (task 7.1) ─────────────────────────────────────────

    /**
     * Mirrors VendorSearchEndpointTest's grace coverage — the detail
     * page must stay in lockstep with search (its own comment says
     * "same visibility floor"), or a vendor could appear in results
     * and then 404 the moment a customer taps in.
     */
    public function test_a_vendor_within_the_grace_window_still_resolves(): void
    {
        [$vendor, $subscription] = $this->activeVendorWithSubscription();
        $vendor->update(['status' => 'grace']);
        $subscription->update(['status' => 'grace', 'end_date' => now()->subDays(3)]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.id', $vendor->id);
    }

    public function test_a_vendor_past_the_grace_window_is_not_found(): void
    {
        [$vendor, $subscription] = $this->activeVendorWithSubscription();
        $vendor->update(['status' => 'grace']);
        $subscription->update(['status' => 'grace', 'end_date' => now()->subDays(10)]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")->assertStatus(404);
    }

    public function test_a_nonexistent_vendor_is_not_found(): void
    {
        $this->getJson('/api/vendors/999999/detail')->assertStatus(404);
    }

    // ── Media (approved-only) ───────────────────────────────────────────

    public function test_only_approved_media_is_returned(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();

        $approved = $vendor->media()->create([
            'disk' => 'public', 'path' => 'a.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'approved',
        ]);
        $vendor->media()->create([
            'disk' => 'public', 'path' => 'b.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'pending',
        ]);
        $vendor->media()->create([
            'disk' => 'public', 'path' => 'c.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'rejected',
        ]);

        $response = $this->getJson("/api/vendors/{$vendor->id}/detail")->assertOk();

        $response->assertJsonCount(1, 'data.media');
        $response->assertJsonPath('data.media.0.id', $approved->id);
    }

    public function test_media_carries_its_service_for_grouping(): void
    {
        [$vendor, , $subcategory] = $this->activeVendorWithSubscription();

        $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public', 'path' => 'a.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'approved',
        ]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.media.0.subcategory_id', $subcategory->id)
            ->assertJsonPath('data.media.0.category_id', $subcategory->category_id)
            ->assertJsonPath('data.media.0.category_name', $subcategory->category->name);
    }

    public function test_media_for_a_service_no_longer_offered_is_hidden(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();
        $dropped = $this->subcategory();

        $vendor->media()->create([
            'subcategory_id' => $dropped->id,
            'disk' => 'public', 'path' => 'old.jpg', 'type' => 'image',
            'size_bytes' => 100, 'moderation_status' => 'approved',
        ]);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonCount(0, 'data.media');
    }

    // ── Distance ─────────────────────────────────────────────────────────

    public function test_distance_is_calculated_when_a_point_is_given(): void
    {
        [$vendor] = $this->activeVendorWithSubscription(); // lat 23.03, lng 72.55

        $response = $this->getJson("/api/vendors/{$vendor->id}/detail?".http_build_query([
            'latitude' => 23.03,
            'longitude' => 72.56,
        ]))->assertOk();

        // A whole-number-valued round() (e.g. 1.0) encodes to JSON as a
        // bare integer, not a float with a trailing .0 — assert
        // numeric, not a specific PHP type.
        $distance = $response->json('data.distance_km');
        $this->assertIsNumeric($distance);
        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(5, $distance); // ~1km at this latitude, sanity bound
    }

    public function test_distance_is_null_when_the_vendor_has_no_location(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();
        $vendor->update(['latitude' => null, 'longitude' => null]);

        // Used to measure from 0,0 and report thousands of kilometres.
        $this->getJson("/api/vendors/{$vendor->id}/detail?".http_build_query([
            'latitude' => 23.03,
            'longitude' => 72.56,
        ]))
            ->assertOk()
            ->assertJsonPath('data.distance_km', null);
    }

    public function test_distance_is_null_when_no_point_is_given(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.distance_km', null);
    }

    // ── Reviews (SPEC section 9, task 5.5) ──────────────────────────────

    private function reviewOn(Vendor $vendor, bool $hidden = false, ?string $vendorReply = null): Review
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory = $this->subcategory();

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
            'rating' => 5,
            'comment' => 'Excellent',
            'is_hidden' => $hidden,
            'vendor_reply' => $vendorReply,
            'replied_at' => $vendorReply === null ? null : now(),
        ]);
    }

    public function test_a_visible_review_appears_with_its_vendor_reply(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();
        $review = $this->reviewOn($vendor, vendorReply: 'Thanks for the kind words!');

        $response = $this->getJson("/api/vendors/{$vendor->id}/detail")->assertOk();

        $response->assertJsonCount(1, 'data.reviews');
        $response->assertJsonPath('data.reviews.0.id', $review->id);
        $response->assertJsonPath('data.reviews.0.rating', 5);
        $response->assertJsonPath('data.reviews.0.vendor_reply', 'Thanks for the kind words!');
        $response->assertJsonPath('data.reviews.0.customer_name', $review->customer->user->name);
    }

    public function test_a_hidden_review_never_appears_in_the_public_response(): void
    {
        [$vendor] = $this->activeVendorWithSubscription();
        $this->reviewOn($vendor, hidden: true);

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonCount(0, 'data.reviews');
    }
}
