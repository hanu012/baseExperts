<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
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
 * GET /api/vendors/search (SPEC section 4 item 4, task 5.3) — the core
 * customer-facing matching query: subcategory + zone coverage on a
 * vendor's active, unexpired subscription, sorted by plan priority, then
 * rating (currently always inert — see VendorSearchService), then
 * recency.
 */
class VendorSearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function leafZoneAt(float $lat, float $lng): Zone
    {
        return Zone::factory()->active()->withBoundary(ZoneFactory::square($lat, $lng))->create();
    }

    private function subcategory(): Subcategory
    {
        $category = Category::factory()->create();

        return Subcategory::factory()->for($category)->create();
    }

    private function planWithPriority(int $priorityRank): Plan
    {
        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => $priorityRank]);

        return $plan->fresh(['quota']);
    }

    private function vendorCovering(
        Subcategory $subcategory,
        Zone $zone,
        Plan $plan,
        array $vendorAttributes = [],
        array $subscriptionAttributes = [],
    ): Vendor {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ], $vendorAttributes));

        $subscription = Subscription::create(array_merge([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ], $subscriptionAttributes));

        SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $vendor;
    }

    // ── Happy path / coverage matching ──────────────────────────────────

    public function test_a_vendor_covering_the_subcategory_and_zone_matches(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $vendor = $this->vendorCovering($subcategory, $zone, $plan);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath('data.zone.id', $zone->id);
        $response->assertJsonCount(1, 'data.vendors');
        $response->assertJsonPath('data.vendors.0.id', $vendor->id);
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_a_vendor_covering_the_subcategory_but_a_different_zone_is_excluded(): void
    {
        $subcategory = $this->subcategory();
        $vendorZone = $this->leafZoneAt(10.0, 10.0);
        $searchZone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($subcategory, $vendorZone, $plan);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');

        // The search zone itself must have been matched — proves the empty
        // result is coverage exclusion, not a failed zone lookup.
        $this->assertNotNull($searchZone);
    }

    public function test_a_vendor_covering_the_zone_but_a_different_subcategory_is_excluded(): void
    {
        $vendorSubcategory = $this->subcategory();
        $searchSubcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($vendorSubcategory, $zone, $plan);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $searchSubcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');
    }

    public function test_a_vendor_with_an_expired_subscription_is_excluded(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($subcategory, $zone, $plan, subscriptionAttributes: [
            'status' => 'active',
            'end_date' => now()->subDay(),
        ]);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');
    }

    // ── Grace window (task 7.1) ─────────────────────────────────────────

    /**
     * SPEC section 7: Grace is a still-visible renewal window, not an
     * exit from search — only Expired actually removes a vendor. Both
     * vendor.status AND subscriptions.status must say 'grace', and
     * end_date must still be within grace_period_days (default 7,
     * seeded by SettingSeeder), or the whole point of this test is
     * moot.
     */
    public function test_a_vendor_within_the_grace_window_still_matches(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $vendor = $this->vendorCovering($subcategory, $zone, $plan,
            vendorAttributes: ['status' => 'grace'],
            subscriptionAttributes: ['status' => 'grace', 'end_date' => now()->subDays(3)],
        );

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()
            ->assertJsonCount(1, 'data.vendors')
            ->assertJsonPath('data.vendors.0.id', $vendor->id);
    }

    public function test_a_vendor_past_the_grace_window_is_excluded(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($subcategory, $zone, $plan,
            vendorAttributes: ['status' => 'grace'],
            subscriptionAttributes: ['status' => 'grace', 'end_date' => now()->subDays(10)],
        );

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');
    }

    public function test_an_expired_vendor_is_excluded(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($subcategory, $zone, $plan,
            vendorAttributes: ['status' => 'expired'],
            subscriptionAttributes: ['status' => 'expired', 'end_date' => now()->subDays(20)],
        );

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');
    }

    public function test_a_suspended_vendor_is_excluded(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);
        $this->vendorCovering($subcategory, $zone, $plan, vendorAttributes: ['is_suspended' => true]);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk()->assertJsonCount(0, 'data.vendors');
    }

    public function test_pincode_search_matches_the_same_way_as_a_point(): void
    {
        $subcategory = $this->subcategory();
        $zone = Zone::factory()->active()->create(['pincode' => '380001']);
        $plan = $this->planWithPriority(1);
        $vendor = $this->vendorCovering($subcategory, $zone, $plan);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'pincode' => '380001',
        ]))->assertOk()
            ->assertJsonPath('data.vendors.0.id', $vendor->id);
    }

    // ── No zone match ────────────────────────────────────────────────────

    public function test_a_point_matching_no_zone_returns_an_empty_result_not_an_error(): void
    {
        $subcategory = $this->subcategory();
        $this->leafZoneAt(23.0, 72.5);

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 10.0,
            'longitude' => 10.0,
        ]))->assertOk()
            ->assertJsonPath('data.zone', null)
            ->assertJsonPath('data.vendors', []);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_missing_subcategory_id_is_rejected(): void
    {
        $this->getJson('/api/vendors/search?'.http_build_query([
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['subcategory_id']]]);
    }

    public function test_neither_a_point_nor_a_pincode_is_rejected(): void
    {
        $subcategory = $this->subcategory();

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
        ]))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['pincode']]]);
    }

    // ── Sort order ───────────────────────────────────────────────────────

    public function test_a_higher_priority_rank_plan_sorts_first_regardless_of_recency(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);

        $lowPriorityPlan = $this->planWithPriority(1);
        $highPriorityPlan = $this->planWithPriority(2);

        // Created first, but on the lower-priority plan.
        $olderLowerPriority = $this->vendorCovering($subcategory, $zone, $lowPriorityPlan);
        $newerHigherPriority = $this->vendorCovering($subcategory, $zone, $highPriorityPlan);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $newerHigherPriority->id);
        $response->assertJsonPath('data.vendors.1.id', $olderLowerPriority->id);
    }

    public function test_same_plan_tier_falls_through_to_recency_while_rating_data_is_absent(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        $older = $this->vendorCovering($subcategory, $zone, $plan);
        $older->forceFill(['created_at' => now()->subDays(5)])->save();

        $newer = $this->vendorCovering($subcategory, $zone, $plan);
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        // Both still have rating_count = 0 (default) — proves the rating
        // tier is genuinely inert, not accidentally deciding this sort.
        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $newer->id);
        $response->assertJsonPath('data.vendors.1.id', $older->id);
    }

    public function test_a_vendor_meeting_the_review_threshold_outranks_an_unrated_vendor_on_the_same_tier(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        $unrated = $this->vendorCovering($subcategory, $zone, $plan);
        $unrated->forceFill(['created_at' => now()])->save(); // newest, but unrated

        $rated = $this->vendorCovering($subcategory, $zone, $plan);
        $rated->forceFill(['created_at' => now()->subDays(10), 'rating_avg' => 4.8, 'rating_count' => 20])->save();

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $rated->id);
        $response->assertJsonPath('data.vendors.1.id', $unrated->id);
    }

    // ── Sort chips (task 5.3's redesign) ────────────────────────────────

    public function test_sort_nearest_orders_within_a_plan_tier_by_distance(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        $far = $this->vendorCovering($subcategory, $zone, $plan, [
            'latitude' => 23.10, 'longitude' => 72.60,
        ]);
        $near = $this->vendorCovering($subcategory, $zone, $plan, [
            'latitude' => 23.001, 'longitude' => 72.501,
        ]);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.0,
            'longitude' => 72.5,
            'sort' => 'nearest',
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $near->id);
        $response->assertJsonPath('data.vendors.1.id', $far->id);

        // distance_km rides along regardless of which chip is selected —
        // "Top rated" still shows a distance, it just is not sorted by it.
        $this->assertIsNumeric($response->json('data.vendors.0.distance_km'));
    }

    public function test_sort_nearest_puts_vendors_without_coordinates_last(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        // NULL distance sorts first ascending unless handled explicitly.
        $unlocated = $this->vendorCovering($subcategory, $zone, $plan, [
            'latitude' => null, 'longitude' => null,
        ]);
        $located = $this->vendorCovering($subcategory, $zone, $plan, [
            'latitude' => 23.10, 'longitude' => 72.60,
        ]);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.0,
            'longitude' => 72.5,
            'sort' => 'nearest',
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $located->id);
        $response->assertJsonPath('data.vendors.1.id', $unlocated->id);
    }

    public function test_a_deactivated_subcategory_returns_no_vendors(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $this->vendorCovering($subcategory, $zone, $this->planWithPriority(1));

        $subcategory->update(['is_active' => false]);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $this->assertSame([], $response->json('data.vendors'));
    }

    public function test_sort_new_orders_within_a_plan_tier_by_recency(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        $older = $this->vendorCovering($subcategory, $zone, $plan);
        $older->forceFill(['created_at' => now()->subDays(10)])->save();

        $newer = $this->vendorCovering($subcategory, $zone, $plan);
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
            'sort' => 'new',
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $newer->id);
        $response->assertJsonPath('data.vendors.1.id', $older->id);
    }

    public function test_plan_priority_still_wins_over_the_chosen_sort(): void
    {
        // A lower-tier vendor sitting right next to the customer must not
        // outrank a higher-tier vendor further away — paid placement is
        // the primary key regardless of which chip the customer taps.
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);

        $nearLowTier = $this->vendorCovering(
            $subcategory,
            $zone,
            $this->planWithPriority(1),
            ['latitude' => 23.001, 'longitude' => 72.501],
        );
        $farHighTier = $this->vendorCovering(
            $subcategory,
            $zone,
            $this->planWithPriority(2),
            ['latitude' => 23.10, 'longitude' => 72.60],
        );

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.0,
            'longitude' => 72.5,
            'sort' => 'nearest',
        ]))->assertOk();

        $response->assertJsonPath('data.vendors.0.id', $farHighTier->id);
        $response->assertJsonPath('data.vendors.1.id', $nearLowTier->id);
    }

    public function test_an_invalid_sort_value_is_rejected(): void
    {
        $subcategory = $this->subcategory();

        $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.0,
            'longitude' => 72.5,
            'sort' => 'cheapest',
        ]))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['sort']]]);
    }

    // ── Service chips ────────────────────────────────────────────────────

    public function test_each_vendor_lists_every_subcategory_their_subscription_covers(): void
    {
        $category = Category::factory()->create();
        $searched = Subcategory::factory()->for($category)->create(['name' => 'AC Gas Filling']);
        $other = Subcategory::factory()->for($category)->create(['name' => 'AC Installation']);
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        $vendor = $this->vendorCovering($searched, $zone, $plan);

        // vendorCovering only links the searched subcategory — add the
        // second one to the same subscription directly.
        SubscriptionItem::create([
            'subscription_id' => $vendor->currentActiveSubscription()->id,
            'item_type' => 'subcategory',
            'item_id' => $other->id,
        ]);

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $searched->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $names = collect($response->json('data.vendors.0.services'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['AC Gas Filling', 'AC Installation'], $names);
    }

    // ── Pagination ───────────────────────────────────────────────────────

    public function test_results_paginate(): void
    {
        $subcategory = $this->subcategory();
        $zone = $this->leafZoneAt(23.0, 72.5);
        $plan = $this->planWithPriority(1);

        for ($i = 0; $i < 3; $i++) {
            $this->vendorCovering($subcategory, $zone, $plan);
        }

        $response = $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $subcategory->id,
            'latitude' => 23.02,
            'longitude' => 72.52,
            'per_page' => 2,
        ]))->assertOk();

        $response->assertJsonCount(2, 'data.vendors');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.per_page', 2);
    }
}
