<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/vendors/{vendor}/favorite + GET /api/customers/me/favorites
 * (SPEC section 4 item 10) — plus is_favorite surfacing on the public
 * search/detail responses.
 */
class FavoriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithUser(): array
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $customer = Customer::create(['user_id' => $user->id]);

        return [$user, $customer];
    }

    /**
     * SEARCH_LAT/SEARCH_LNG below always falls inside this vendor's
     * zone, so every test can search with the same fixed point.
     */
    private const SEARCH_LAT = 23.02;

    private const SEARCH_LNG = 72.52;

    private function activeVendor(array $overrides = []): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'address' => '12 MG Road',
            'latitude' => self::SEARCH_LAT,
            'longitude' => self::SEARCH_LNG,
            'status' => 'active',
        ], $overrides));

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zone = Zone::factory()->active()->withBoundary(ZoneFactory::square(23.0, 72.5))->create();
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

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $vendor;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // ── Toggle ───────────────────────────────────────────────────────────

    public function test_toggling_favorites_a_vendor_that_was_not_favorited(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->activeVendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('favorites', ['vendor_id' => $vendor->id]);
    }

    public function test_toggling_again_unfavorites_it(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->activeVendor();

        $this->actingAs($user, 'sanctum')->postJson("/api/vendors/{$vendor->id}/favorite")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/favorite")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_a_customer_role_user_with_no_customer_row_gets_404(): void
    {
        // role:customer only guarantees the token's role claim, not that
        // a Customer profile row actually exists for it — same edge
        // case /vendors/me/* guards against for vendor.
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        $vendor = $this->activeVendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/favorite")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_a_vendor_role_token_is_rejected_by_the_role_gate(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = $this->activeVendor();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/vendors/{$vendor->id}/favorite")
            ->assertStatus(403);
    }

    // ── List ─────────────────────────────────────────────────────────────

    public function test_the_favorites_list_returns_only_the_callers_own_favorites(): void
    {
        [$user, $customer] = $this->customerWithUser();
        $favorited = $this->activeVendor();
        $notFavorited = $this->activeVendor();

        $this->actingAs($user, 'sanctum')->postJson("/api/vendors/{$favorited->id}/favorite")->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/me/favorites')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$favorited->id], $ids);
        $this->assertNotContains($notFavorited->id, $ids);
    }

    public function test_the_favorites_list_is_paginated(): void
    {
        [$user, $customer] = $this->customerWithUser();

        foreach (range(1, 3) as $i) {
            $vendor = $this->activeVendor();
            $this->actingAs($user, 'sanctum')->postJson("/api/vendors/{$vendor->id}/favorite")->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/me/favorites?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');
    }

    // ── is_favorite on the public search/detail responses ──────────────────

    public function test_is_favorite_is_false_for_a_guest_on_search(): void
    {
        $vendor = $this->activeVendor();

        $subcategoryId = SubscriptionItem::where('subscription_id', $vendor->subscriptions()->first()->id)
            ->where('item_type', 'subcategory')
            ->value('item_id');

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategoryId,
            'latitude' => self::SEARCH_LAT,
            'longitude' => self::SEARCH_LNG,
        ]))
            ->assertOk()
            ->assertJsonPath('data.vendors.0.is_favorite', false);
    }

    public function test_is_favorite_reflects_true_for_the_owning_customer_on_search_and_detail(): void
    {
        [$user] = $this->customerWithUser();
        $vendor = $this->activeVendor();
        $token = $this->tokenFor($user);

        $this->actingAs($user, 'sanctum')->postJson("/api/vendors/{$vendor->id}/favorite")->assertOk();
        $this->app['auth']->forgetGuards();

        $subcategoryId = SubscriptionItem::where('subscription_id', $vendor->subscriptions()->first()->id)
            ->where('item_type', 'subcategory')
            ->value('item_id');

        $this->withToken($token)
            ->getJson('/api/vendors/search?'.http_build_query([
                'subcategory_id' => $subcategoryId,
                'latitude' => self::SEARCH_LAT,
                'longitude' => self::SEARCH_LNG,
            ]))
            ->assertOk()
            ->assertJsonPath('data.vendors.0.is_favorite', true);

        $this->withToken($token)
            ->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);
    }

    public function test_is_favorite_is_false_for_a_different_customers_token(): void
    {
        [$owner] = $this->customerWithUser();
        [$otherUser] = $this->customerWithUser();
        $vendor = $this->activeVendor();

        $this->actingAs($owner, 'sanctum')->postJson("/api/vendors/{$vendor->id}/favorite")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($this->tokenFor($otherUser))
            ->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);
    }

    public function test_the_detail_endpoint_still_works_for_a_guest_with_no_token(): void
    {
        $vendor = $this->activeVendor();

        $this->getJson("/api/vendors/{$vendor->id}/detail")
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);
    }
}
