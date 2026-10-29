<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\SubZoneResource\Pages\CreateSubZone;
use App\Filament\Resources\SubZoneResource\Pages\ListSubZones;
use App\Filament\Resources\ZoneResource;
use App\Filament\Resources\ZoneResource\Pages\CreateZone;
use App\Filament\Resources\ZoneResource\Pages\EditZone;
use App\Filament\Resources\ZoneResource\Pages\ListZones;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ZoneResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    public function test_the_list_page_renders(): void
    {
        Zone::factory()->count(3)->create();

        Livewire::test(ListZones::class)->assertSuccessful();
    }

    public function test_the_zones_screen_lists_main_zones_and_the_sub_zones_screen_lists_sub_zones(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);
        $area = Zone::factory()->create(['parent_id' => $city->id, 'name' => 'Gota']);

        Livewire::test(ListZones::class)
            ->assertCanSeeTableRecords([$city])
            ->assertCanNotSeeTableRecords([$area]);

        Livewire::test(ListSubZones::class)
            ->assertCanSeeTableRecords([$area])
            ->assertCanNotSeeTableRecords([$city]);
    }

    public function test_an_unused_zone_can_be_deleted_from_the_list_and_the_edit_page(): void
    {
        $listed = Zone::factory()->create();
        $edited = Zone::factory()->create();

        Livewire::test(ListZones::class)
            ->callTableAction(DeleteAction::class, $listed)
            ->assertHasNoTableActionErrors();

        Livewire::test(EditZone::class, ['record' => $edited->getKey()])
            ->callAction('delete');

        $this->assertModelMissing($listed);
        $this->assertModelMissing($edited);
    }

    public function test_an_unused_sub_zone_can_be_deleted(): void
    {
        $area = Zone::factory()->create(['parent_id' => Zone::factory()->create()->id]);

        Livewire::test(ListSubZones::class)
            ->callTableAction(DeleteAction::class, $area);

        $this->assertModelMissing($area);
    }

    /** Deleting it would silently turn its sub-zones into main zones. */
    public function test_a_main_zone_with_sub_zones_cannot_be_deleted(): void
    {
        $city = Zone::factory()->create();
        Zone::factory()->create(['parent_id' => $city->id]);

        Livewire::test(ListZones::class)
            ->assertTableActionDisabled(DeleteAction::class, $city)
            ->callTableAction(DeleteAction::class, $city);

        $this->assertModelExists($city);
    }

    public function test_a_subscribed_zone_cannot_be_deleted(): void
    {
        $zone = Zone::factory()->create();
        $this->subscribeTo($zone);

        Livewire::test(ListZones::class)
            ->assertTableActionDisabled(DeleteAction::class, $zone)
            ->callTableAction(DeleteAction::class, $zone);

        $this->assertModelExists($zone);
    }

    /** One record at a time, so each gets its own checks. */
    public function test_there_is_no_bulk_delete(): void
    {
        Livewire::test(ListZones::class)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }

    public function test_delete_needs_the_zones_delete_permission(): void
    {
        $zone = Zone::factory()->create();

        $this->actingAs(User::factory()->subAdmin(['zones.viewAny', 'zones.update'])->create());

        Livewire::test(ListZones::class)
            ->assertTableActionHidden(DeleteAction::class, $zone);
    }

    private function subscribeTo(Zone $zone): void
    {
        $vendor = \App\Models\Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'Zone Cooling',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345671',
            'status' => 'active',
        ]);
        $plan = \App\Models\Plan::factory()->create();
        $subscription = \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        \App\Models\SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Status changes go through the reasoned "Change status" action; the
     * value is read back from the database rather than trusting the
     * component.
     */
    public function test_change_status_flips_the_record(): void
    {
        $zone = Zone::factory()->create();

        $this->assertFalse($zone->is_active);

        Livewire::test(ListZones::class)
            ->assertTableColumnFormattedStateSet('is_active', 'Draft', $zone)
            ->callTableAction('changeStatus', $zone, data: [
                'is_active' => '1',
                'reason' => 'Boundary checked against the map.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($zone->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status_changed',
            'auditable_id' => $zone->getKey(),
        ]);
    }

    public function test_a_zone_can_be_drawn_and_saved_as_a_draft(): void
    {
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Gota',
                'slug' => 'gota',
                'pincode' => '382481',
                'polygon_points' => ZoneFactory::square(23.09, 72.52),
                'is_active' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $zone = Zone::where('slug', 'gota')->firstOrFail();

        $this->assertFalse($zone->is_active);

        // The boundary really landed in Ahmedabad, not a transposed location.
        $inside = DB::table('zones')
            ->selectRaw('ST_Contains(polygon, ST_GeomFromText(?, 4326)) as inside', ['POINT(72.53 23.10)'])
            ->where('id', $zone->getKey())
            ->value('inside');

        $this->assertSame(1, (int) $inside);
    }

    public function test_a_zone_cannot_be_saved_without_a_boundary(): void
    {
        // polygon is NOT NULL (SPEC section 11), so the form must refuse.
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'No Shape',
                'slug' => 'no-shape',
                'polygon_points' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['polygon_points']);

        $this->assertDatabaseMissing('zones', ['slug' => 'no-shape']);
    }

    public function test_editing_loads_the_existing_boundary_into_the_form(): void
    {
        $zone = Zone::factory()->withBoundary([
            ['lat' => 23.09, 'lng' => 72.52],
            ['lat' => 23.09, 'lng' => 72.58],
            ['lat' => 23.13, 'lng' => 72.58],
            ['lat' => 23.13, 'lng' => 72.52],
        ])->create();

        Livewire::test(EditZone::class, ['record' => $zone->getKey()])
            ->assertFormSet(function (array $state): bool {
                $points = $state['polygon_points'] ?? [];

                return count($points) === 4
                    && abs($points[0]['lat'] - 23.09) < 0.0001
                    && abs($points[0]['lng'] - 72.52) < 0.0001;
            });
    }

    public function test_a_sub_zone_can_reuse_a_slug_under_a_different_parent(): void
    {
        $ahmedabad = Zone::factory()->create(['name' => 'Ahmedabad', 'slug' => 'ahmedabad']);
        $surat = Zone::factory()->create(['name' => 'Surat', 'slug' => 'surat']);

        Zone::factory()->create(['parent_id' => $ahmedabad->id, 'slug' => 'central']);

        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Central',
                'slug' => 'central',
                'parent_id' => $surat->id,
                'polygon_points' => ZoneFactory::square(21.17, 72.83),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Zone::where('slug', 'central')->count());
    }

    public function test_a_duplicate_slug_under_the_same_parent_is_rejected(): void
    {
        $parent = Zone::factory()->create();
        Zone::factory()->create(['parent_id' => $parent->id, 'slug' => 'central']);

        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Central',
                'slug' => 'central',
                'parent_id' => $parent->id,
                'polygon_points' => ZoneFactory::square(23.2, 72.6),
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    /**
     * SPEC section 8 caps the hierarchy at two levels (city -> sub-zone).
     * At exactly two levels, "effective active checks one level up" and
     * "in use counts every descendant" are the same statement, so they cannot
     * disagree — a third level would silently break that equivalence.
     */
    public function test_a_grandchild_zone_is_rejected(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);
        $subZone = Zone::factory()->create(['parent_id' => $city->id, 'name' => 'Gota']);

        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Gota North',
                'slug' => 'gota-north',
                'parent_id' => $subZone->id,
                'polygon_points' => ZoneFactory::square(23.10, 72.53),
            ])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);

        $this->assertDatabaseMissing('zones', ['slug' => 'gota-north']);
    }

    public function test_a_first_sub_zone_under_a_directly_subscribed_zone_is_rejected(): void
    {
        // Making a subscribed leaf into a parent would drop its vendors out
        // of search, since only leaves are ever matched.
        $standalone = Zone::factory()->active()->create(['name' => 'Sanand']);

        $vendorUser = User::factory()->role(UserRole::Vendor)->create();
        $vendor = \App\Models\Vendor::create([
            'user_id' => $vendorUser->id,
            'business_name' => 'Sanand Cooling',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345670',
            'status' => 'active',
        ]);
        $plan = \App\Models\Plan::factory()->create();
        $subscription = \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        \App\Models\SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'zone', 'item_id' => $standalone->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Sanand GIDC',
                'slug' => 'sanand-gidc',
                'parent_id' => $standalone->id,
                'polygon_points' => ZoneFactory::square(-33.0, -70.0),
            ])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);

        $this->assertDatabaseMissing('zones', ['slug' => 'sanand-gidc']);
    }

    public function test_a_sub_zone_under_a_top_level_zone_is_allowed(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);

        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Ranip',
                'slug' => 'ranip',
                'parent_id' => $city->id,
                'polygon_points' => ZoneFactory::square(23.07, 72.55),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('zones', ['slug' => 'ranip', 'parent_id' => $city->id]);
    }

    public function test_editing_the_boundary_persists_the_new_polygon(): void
    {
        $zone = Zone::factory()->create(['name' => 'Naranpura']);

        $before = DB::table('zones')
            ->selectRaw('ST_AsText(polygon) as wkt')
            ->where('id', $zone->getKey())
            ->value('wkt');

        // A boundary somewhere clearly different from the factory's.
        $moved = ZoneFactory::square(23.06, 72.56);

        Livewire::test(EditZone::class, ['record' => $zone->getKey()])
            ->fillForm(['polygon_points' => $moved])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = DB::table('zones')
            ->selectRaw('ST_AsText(polygon) as wkt')
            ->where('id', $zone->getKey())
            ->value('wkt');

        $this->assertNotSame($before, $after, 'The stored boundary did not change.');

        // And it round-trips back into the map as the points just saved.
        $this->assertEqualsWithDelta(
            $moved[0]['lat'],
            Zone::pointsFromWkt($after)[0]['lat'],
            0.0001,
        );
    }

    /**
     * A main zone cannot be turned into a sub-zone from its own screen —
     * the parent field is hidden there, not disabled, so it is not in the
     * form state at all (which also keeps the three-level tree closed from
     * that direction).
     */
    public function test_the_main_zone_form_has_no_parent_field(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);

        Livewire::test(CreateZone::class)->assertFormFieldIsHidden('parent_id');
        Livewire::test(EditZone::class, ['record' => $city->getKey()])->assertFormFieldIsHidden('parent_id');
    }

    public function test_a_sub_zone_needs_a_main_zone(): void
    {
        Livewire::test(CreateSubZone::class)
            ->fillForm([
                'name' => 'Orphan',
                'slug' => 'orphan',
                'polygon_points' => ZoneFactory::square(-33.0, -70.0),
            ])
            ->call('create')
            ->assertHasFormErrors(['parent_id' => 'required']);
    }

    public function test_the_parent_dropdown_offers_only_top_level_zones(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);
        $subZone = Zone::factory()->create(['parent_id' => $city->id, 'name' => 'Gota']);

        Livewire::test(CreateSubZone::class)
            ->assertFormFieldExists('parent_id', function ($field) use ($city, $subZone): bool {
                $options = $field->getOptions();

                return array_key_exists($city->id, $options)
                    && ! array_key_exists($subZone->id, $options);
            });
    }

    public function test_the_map_field_renders_with_the_configured_browser_key(): void
    {
        // Proves the whole chain: the custom field's Blade view compiles, the
        // Alpine component is wired up, and config/map.php reaches the page.
        config()->set('map.google_browser_key', 'test-browser-key-123');

        Livewire::test(CreateZone::class)
            ->assertSuccessful()
            ->assertSee('zoneMap(', escape: false)
            ->assertSee('test-browser-key-123', escape: false);
    }

    public function test_the_server_side_places_key_is_never_rendered(): void
    {
        // The Places key is billable and unrestricted by referrer; only the
        // browser key may reach the page.
        config()->set('map.google_api_key', 'server-only-places-key');
        config()->set('map.google_browser_key', 'browser-key');

        $html = Livewire::test(CreateZone::class)->assertSuccessful()->html();

        $this->assertStringNotContainsString('server-only-places-key', $html);
    }

    public function test_the_map_field_has_no_cdn_references(): void
    {
        // Google Maps is the one allowed remote script (CLAUDE.md); nothing
        // else in the panel should reach out to a CDN.
        $html = Livewire::test(CreateZone::class)->assertSuccessful()->html();

        $this->assertStringNotContainsString('unpkg.com', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }

    public function test_a_circle_zone_is_saved_as_a_matching_polygon(): void
    {
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Circle Test',
                'slug' => 'circle-test',
                'shape_type' => 'circle',
                // Outside the seeded Ahmedabad range, per CLAUDE.md.
                'center_lat' => -33.0,
                'center_lng' => -70.0,
                'radius_km' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $zone = Zone::where('slug', 'circle-test')->firstOrFail();

        $this->assertSame('circle', $zone->shape_type);
        $this->assertEqualsWithDelta(-33.0, $zone->center_lat, 0.000001);
        $this->assertEqualsWithDelta(2.0, $zone->radius_km, 0.0001);

        $contains = fn (string $point): int => (int) DB::table('zones')
            ->selectRaw('ST_Contains(polygon, ST_GeomFromText(?, 4326)) as inside', [$point])
            ->where('id', $zone->getKey())
            ->value('inside');

        // ~1.1 km north of the centre is inside a 2 km circle; ~3.3 km is not.
        $this->assertSame(1, $contains('POINT(-70.0 -32.99)'));
        $this->assertSame(0, $contains('POINT(-70.0 -32.97)'));
    }

    public function test_a_circle_zone_needs_a_centre_and_radius(): void
    {
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Half Circle',
                'slug' => 'half-circle',
                'shape_type' => 'circle',
                'center_lat' => null,
                'center_lng' => null,
                'radius_km' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['center_lat', 'center_lng', 'radius_km']);

        $this->assertDatabaseMissing('zones', ['slug' => 'half-circle']);
    }

    public function test_switching_a_circle_back_to_a_polygon_clears_the_circle(): void
    {
        $zone = Zone::factory()->create([
            'shape_type' => 'circle',
            'center_lat' => 23.1,
            'center_lng' => 72.55,
            'radius_km' => 1.5,
        ]);

        Livewire::test(EditZone::class, ['record' => $zone->getKey()])
            ->fillForm([
                'shape_type' => 'polygon',
                'polygon_points' => ZoneFactory::square(23.06, 72.56),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $zone->refresh();

        $this->assertSame('polygon', $zone->shape_type);
        $this->assertNull($zone->center_lat);
        $this->assertNull($zone->radius_km);
    }

    public function test_a_zone_saved_without_a_code_gets_a_default_one(): void
    {
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Codeless',
                'slug' => 'codeless',
                'polygon_points' => ZoneFactory::square(23.09, 72.52),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $zone = Zone::where('slug', 'codeless')->firstOrFail();

        $this->assertSame(sprintf('ZONE-%03d', $zone->getKey()), $zone->code);
    }

    public function test_a_typed_code_is_uppercased_and_must_be_unique(): void
    {
        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Gota',
                'slug' => 'gota',
                'code' => 'zone-amd-001',
                'polygon_points' => ZoneFactory::square(23.09, 72.52),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('zones', ['slug' => 'gota', 'code' => 'ZONE-AMD-001']);

        Livewire::test(CreateZone::class)
            ->fillForm([
                'name' => 'Ranip',
                'slug' => 'ranip',
                'code' => 'ZONE-AMD-001',
                'polygon_points' => ZoneFactory::square(23.07, 72.55),
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_the_zone_areas_tab_asks_to_save_first_on_create(): void
    {
        Livewire::test(CreateZone::class)
            ->assertSuccessful()
            ->assertSee('Save the zone first to add areas.');
    }

    public function test_the_zone_areas_tab_lists_sub_zones_on_edit(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);
        Zone::factory()->create(['parent_id' => $city->id, 'name' => 'Gota', 'code' => 'ZONE-GOTA']);

        Livewire::test(EditZone::class, ['record' => $city->getKey()])
            ->assertSuccessful()
            ->assertSee('ZONE-GOTA')
            ->assertSee('Add area');
    }

    public function test_add_area_prefills_the_parent_zone(): void
    {
        $city = Zone::factory()->create(['name' => 'Ahmedabad']);

        Livewire::withQueryParams(['parent_id' => $city->id])
            ->test(CreateSubZone::class)
            ->assertFormSet(['parent_id' => $city->id]);
    }

    public function test_a_non_admin_cannot_reach_the_resource(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get(ZoneResource::getUrl('index'))->assertForbidden();
    }
}
