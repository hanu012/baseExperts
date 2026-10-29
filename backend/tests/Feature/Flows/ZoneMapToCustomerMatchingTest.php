<?php

namespace Tests\Feature\Flows;

use App\Enums\UserRole;
use App\Filament\Resources\ZoneResource\Pages\CreateZone;
use App\Filament\Resources\ZoneResource\Pages\EditZone;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End to end: a zone drawn on the admin map → a vendor covering it → a
 * customer at a location inside it finds that vendor.
 *
 * Zones are created through the real admin form (CreateZone/EditZone, the
 * same Livewire component the Google map posts its shape to), and the
 * customer side goes through GET /api/vendors/search, the call the
 * customer app makes with the location it has set. Nothing in between is
 * stubbed: the shape is stored, matched with ST_Contains by ZoneMatcher,
 * and joined to the vendor's subscription.
 *
 * Coordinates sit around -33, -70, outside the seeded Ahmedabad zones (see
 * CLAUDE.md), so a real seeded zone can never be the one that matches.
 */
class ZoneMapToCustomerMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Subcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        $this->subcategory = Subcategory::factory()->for(Category::factory())->create();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Creates a zone through the admin form, the way the map submits it. */
    private function createZoneThroughAdmin(array $form): Zone
    {
        $slug = Str::slug($form['name']);

        Livewire::test(CreateZone::class)
            ->fillForm(array_merge(['slug' => $slug, 'is_active' => true], $form))
            ->call('create')
            ->assertHasNoFormErrors();

        return Zone::where('slug', $slug)->firstOrFail();
    }

    private function vendorCovering(Zone $zone): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create(['price_paise' => 99_900, 'duration_days' => 365]);
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
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $this->subcategory->id, 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $vendor;
    }

    /** What the customer app calls once the customer has set a location. */
    private function customerSearchesAt(float $lat, float $lng): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/vendors/search?'.http_build_query([
            'subcategory_id' => $this->subcategory->id,
            'latitude' => $lat,
            'longitude' => $lng,
        ]))->assertOk();
    }

    // ── Polygon zones ────────────────────────────────────────────────────

    public function test_a_polygon_drawn_on_the_map_matches_a_customer_inside_it(): void
    {
        // A 0.04° square, south-west corner at -33.5, -70.5.
        $zone = $this->createZoneThroughAdmin([
            'name' => 'Polygon Zone',
            'shape_type' => 'polygon',
            'polygon_points' => ZoneFactory::square(-33.5, -70.5),
        ]);
        $vendor = $this->vendorCovering($zone);

        $this->customerSearchesAt(-33.48, -70.48)
            ->assertJsonPath('data.zone.id', $zone->id)
            ->assertJsonPath('data.vendors.0.id', $vendor->id);

        // Just outside the square's east edge.
        $this->customerSearchesAt(-33.48, -70.45)
            ->assertJsonPath('data.zone', null)
            ->assertJsonCount(0, 'data.vendors');
    }

    public function test_redrawing_the_boundary_moves_who_is_matched(): void
    {
        $zone = $this->createZoneThroughAdmin([
            'name' => 'Moving Zone',
            'shape_type' => 'polygon',
            'polygon_points' => ZoneFactory::square(-33.5, -70.5),
        ]);
        $this->vendorCovering($zone);

        // The admin drags the boundary somewhere else on the map.
        Livewire::test(EditZone::class, ['record' => $zone->getKey()])
            ->fillForm(['polygon_points' => ZoneFactory::square(-33.4, -70.4)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->customerSearchesAt(-33.48, -70.48)->assertJsonCount(0, 'data.vendors');
        $this->customerSearchesAt(-33.38, -70.38)->assertJsonPath('data.zone.id', $zone->id);
    }

    // ── Circle zones ─────────────────────────────────────────────────────

    public function test_a_circle_matches_inside_its_radius_and_not_beyond(): void
    {
        // 2 km around -33.0, -70.0. 0.01° of latitude ≈ 1.11 km.
        $zone = $this->createZoneThroughAdmin([
            'name' => 'Circle Zone',
            'shape_type' => 'circle',
            'center_lat' => -33.0,
            'center_lng' => -70.0,
            'radius_km' => 2,
        ]);
        $vendor = $this->vendorCovering($zone);

        // At the centre, ~1.1 km north, and ~1.9 km north: all inside.
        foreach ([-33.0, -32.99, -32.9829] as $lat) {
            $this->customerSearchesAt($lat, -70.0)
                ->assertJsonPath('data.zone.id', $zone->id)
                ->assertJsonPath('data.vendors.0.id', $vendor->id);
        }

        // ~2.1 km and ~3.3 km north: outside.
        foreach ([-32.9811, -32.97] as $lat) {
            $this->customerSearchesAt($lat, -70.0)->assertJsonCount(0, 'data.vendors');
        }
    }

    public function test_shrinking_the_radius_drops_customers_now_outside_it(): void
    {
        $zone = $this->createZoneThroughAdmin([
            'name' => 'Shrinking Circle',
            'shape_type' => 'circle',
            'center_lat' => -33.0,
            'center_lng' => -70.0,
            'radius_km' => 2,
        ]);
        $this->vendorCovering($zone);

        $this->customerSearchesAt(-32.99, -70.0)->assertJsonCount(1, 'data.vendors');

        Livewire::test(EditZone::class, ['record' => $zone->getKey()])
            ->fillForm(['radius_km' => 0.5])
            ->call('save')
            ->assertHasNoFormErrors();

        // ~1.1 km out is now beyond a 0.5 km radius; the centre still matches.
        $this->customerSearchesAt(-32.99, -70.0)->assertJsonCount(0, 'data.vendors');
        $this->customerSearchesAt(-33.0, -70.0)->assertJsonCount(1, 'data.vendors');
    }

    // ── Draft zones and areas ────────────────────────────────────────────

    public function test_a_draft_zone_is_not_matched_until_switched_on(): void
    {
        $zone = $this->createZoneThroughAdmin([
            'name' => 'Draft Zone',
            'shape_type' => 'polygon',
            'polygon_points' => ZoneFactory::square(-33.5, -70.5),
            'is_active' => false,
        ]);
        $this->vendorCovering($zone);

        $this->customerSearchesAt(-33.48, -70.48)->assertJsonCount(0, 'data.vendors');

        $zone->update(['is_active' => true]);

        $this->customerSearchesAt(-33.48, -70.48)->assertJsonCount(1, 'data.vendors');
    }

    public function test_a_customer_inside_an_area_is_matched_to_the_area_not_the_city(): void
    {
        // City: a big 0.2° square. Area (sub-zone) added from its Zone Areas
        // tab: a 0.04° square inside it.
        $city = $this->createZoneThroughAdmin([
            'name' => 'Test City',
            'shape_type' => 'polygon',
            'polygon_points' => ZoneFactory::square(-33.6, -70.6, 0.2),
        ]);

        $area = $this->createZoneThroughAdmin([
            'name' => 'Test Area',
            'parent_id' => $city->id,
            'shape_type' => 'circle',
            'center_lat' => -33.5,
            'center_lng' => -70.5,
            'radius_km' => 1,
        ]);

        $vendor = $this->vendorCovering($area);

        $this->customerSearchesAt(-33.5, -70.5)
            ->assertJsonPath('data.zone.id', $area->id)
            ->assertJsonPath('data.vendors.0.id', $vendor->id);

        // Inside the city but outside every area: a city with areas is never
        // matched directly, so nobody is shown.
        $this->customerSearchesAt(-33.42, -70.42)
            ->assertJsonPath('data.zone', null)
            ->assertJsonCount(0, 'data.vendors');
    }
}
