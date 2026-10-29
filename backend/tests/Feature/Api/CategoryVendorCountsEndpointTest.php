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
 * GET /api/categories/{category}/vendor-counts — the customer
 * subcategories screen's "X vendors" line (SPEC section 4 item 3's
 * redesign). Same vendor-eligibility rule as /api/vendors/search
 * (status, active subscription, zone coverage); this just counts
 * several subcategories in one query instead of searching one.
 */
class CategoryVendorCountsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function leafZoneAt(float $lat, float $lng): Zone
    {
        return Zone::factory()->active()->withBoundary(ZoneFactory::square($lat, $lng))->create();
    }

    private function vendorCovering(Subcategory $subcategory, Zone $zone): Vendor
    {
        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

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

    public function test_is_public_and_needs_no_token(): void
    {
        $category = Category::factory()->create();

        $this->getJson("/api/categories/{$category->id}/vendor-counts")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_counts_vendors_covering_each_subcategory_in_the_resolved_zone(): void
    {
        $category = Category::factory()->create();
        $gasFilling = Subcategory::factory()->for($category)->create();
        $installation = Subcategory::factory()->for($category)->create();
        $zone = $this->leafZoneAt(23.0, 72.5);

        $this->vendorCovering($gasFilling, $zone);
        $this->vendorCovering($gasFilling, $zone);
        $this->vendorCovering($installation, $zone);

        $response = $this->getJson('/api/categories/'.$category->id.'/vendor-counts?'.http_build_query([
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath("data.counts.{$gasFilling->id}", 2);
        $response->assertJsonPath("data.counts.{$installation->id}", 1);
        $response->assertJsonPath('data.zone.id', $zone->id);
    }

    public function test_a_subcategory_with_no_covering_vendor_is_zero_not_absent(): void
    {
        $category = Category::factory()->create();
        $lonely = Subcategory::factory()->for($category)->create();
        $zone = $this->leafZoneAt(23.0, 72.5);

        $response = $this->getJson('/api/categories/'.$category->id.'/vendor-counts?'.http_build_query([
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath("data.counts.{$lonely->id}", 0);
    }

    public function test_no_location_returns_every_count_as_zero_rather_than_an_error(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

        $response = $this->getJson("/api/categories/{$category->id}/vendor-counts")->assertOk();

        $response->assertJsonPath('data.zone', null);
        $response->assertJsonPath("data.counts.{$subcategory->id}", 0);
    }

    public function test_a_point_matching_no_zone_returns_every_count_as_zero(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

        // Far from any seeded/test zone.
        $response = $this->getJson('/api/categories/'.$category->id.'/vendor-counts?'.http_build_query([
            'latitude' => -33.0,
            'longitude' => -70.0,
        ]))->assertOk();

        $response->assertJsonPath('data.zone', null);
        $response->assertJsonPath("data.counts.{$subcategory->id}", 0);
    }

    public function test_a_vendor_in_a_different_zone_does_not_count(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $zoneA = $this->leafZoneAt(23.0, 72.5);
        $zoneB = $this->leafZoneAt(24.0, 73.5);

        $this->vendorCovering($subcategory, $zoneB);

        $response = $this->getJson('/api/categories/'.$category->id.'/vendor-counts?'.http_build_query([
            'latitude' => 23.02,
            'longitude' => 72.52,
        ]))->assertOk();

        $response->assertJsonPath("data.counts.{$subcategory->id}", 0);
    }
}
