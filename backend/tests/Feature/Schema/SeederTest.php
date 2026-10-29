<?php

namespace Tests\Feature\Schema;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\Plan;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_catalogue_is_seeded(): void
    {
        $this->assertSame(10, Category::count());
        $this->assertSame(40, Subcategory::count());
    }

    public function test_every_category_has_subcategories_and_is_active(): void
    {
        // A category with no subcategories can never match a vendor, since
        // matching happens one level down (SPEC section 4.4).
        foreach (Category::with('subcategories')->get() as $category) {
            $this->assertTrue($category->is_active, "{$category->name} is inactive");
            $this->assertGreaterThan(0, $category->subcategories->count(), "{$category->name} has none");
        }

        $this->assertSame(40, Subcategory::where('is_active', true)->count());
    }

    public function test_the_public_api_returns_the_seeded_tree(): void
    {
        // The seeded platform must actually be browsable, not merely present
        // in the database.
        $response = $this->getJson('/api/categories')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertCount(4, $response->json('data.0.subcategories'));
    }

    public function test_zones_are_exactly_two_levels_deep(): void
    {
        // SPEC section 8 caps the hierarchy at two levels.
        $ahmedabad = Zone::whereNull('parent_id')->sole();

        $this->assertSame('Ahmedabad', $ahmedabad->name);
        $this->assertSame(15, $ahmedabad->children()->count());

        // No grandchildren: every child must itself be childless.
        foreach ($ahmedabad->children as $child) {
            $this->assertSame(0, $child->children()->count(), "{$child->name} has children");
        }
    }

    public function test_the_parent_is_not_a_leaf_but_every_sub_zone_is(): void
    {
        $ahmedabad = Zone::whereNull('parent_id')->sole();

        $this->assertFalse($ahmedabad->isLeaf());

        foreach ($ahmedabad->children as $child) {
            $this->assertTrue($child->isLeaf(), "{$child->name} is not a leaf");
        }
    }

    /**
     * The parent must be active too. Effective status is
     * `own is_active AND (no parent OR parent.is_active)`, so an inactive
     * Ahmedabad would silently make all 15 sub-zones unmatchable.
     */
    public function test_every_zone_including_the_parent_is_active(): void
    {
        $this->assertSame(16, Zone::count());
        $this->assertSame(16, Zone::where('is_active', true)->count());
    }

    public function test_each_sub_zone_contains_its_own_centre(): void
    {
        foreach (ZoneSeeder::subZones() as $name => [$latitude, $longitude, $pincode]) {
            $zone = Zone::where('name', $name)->sole();

            $contains = DB::table('zones')
                ->selectRaw('ST_Contains(polygon, ST_GeomFromText(?, 4326)) as inside', [
                    sprintf('POINT(%.6F %.6F)', $longitude, $latitude),
                ])
                ->where('id', $zone->id)
                ->value('inside');

            $this->assertSame(1, (int) $contains, "{$name} does not contain its own centre");
            $this->assertSame($pincode, $zone->pincode);
        }
    }

    /**
     * Overlapping sub-zones would match one customer to two zones, distorting
     * both search results and the lead records written against them.
     */
    public function test_no_two_sub_zones_overlap(): void
    {
        $zones = Zone::whereNotNull('parent_id')->pluck('name', 'id')->all();
        $ids = array_keys($zones);

        $overlaps = [];

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $intersects = DB::selectOne(
                    'SELECT ST_Intersects(a.polygon, b.polygon) AS hit
                     FROM zones a, zones b WHERE a.id = ? AND b.id = ?',
                    [$a, $b]
                )->hit;

                if ((int) $intersects === 1) {
                    $overlaps[] = $zones[$a].' / '.$zones[$b];
                }
            }
        }

        $this->assertSame([], $overlaps, 'Overlapping sub-zones: '.implode(', ', $overlaps));
    }

    public function test_the_parent_polygon_encloses_every_sub_zone(): void
    {
        $ahmedabad = Zone::whereNull('parent_id')->sole();

        foreach (Zone::whereNotNull('parent_id')->get() as $child) {
            $within = DB::selectOne(
                'SELECT ST_Contains(p.polygon, c.polygon) AS hit
                 FROM zones p, zones c WHERE p.id = ? AND c.id = ?',
                [$ahmedabad->id, $child->id]
            )->hit;

            $this->assertSame(1, (int) $within, "Ahmedabad does not enclose {$child->name}");
        }
    }

    public function test_the_three_plans_are_seeded_with_quotas(): void
    {
        $this->assertSame(3, Plan::count());

        foreach (['silver', 'gold', 'platinum'] as $slug) {
            $plan = Plan::where('slug', $slug)->sole();

            $this->assertNotNull($plan->quota, "{$slug} has no quota");
            $this->assertTrue($plan->is_active);
            $this->assertSame(365, $plan->duration_days);
        }
    }

    public function test_the_plan_ladder_ascends_on_every_dimension(): void
    {
        $silver = Plan::where('slug', 'silver')->sole();
        $gold = Plan::where('slug', 'gold')->sole();
        $platinum = Plan::where('slug', 'platinum')->sole();

        $this->assertLessThan($gold->price_paise, $silver->price_paise);
        $this->assertLessThan($platinum->price_paise, $gold->price_paise);

        foreach (['max_categories', 'max_subcategories', 'max_zones', 'max_photos', 'max_videos', 'priority_rank'] as $field) {
            $this->assertLessThan($gold->quota->$field, $silver->quota->$field, "silver/gold {$field}");
            $this->assertLessThan($platinum->quota->$field, $gold->quota->$field, "gold/platinum {$field}");
        }
    }

    public function test_prices_are_the_expected_paise_values(): void
    {
        // Integer paise, never float (CLAUDE.md).
        $this->assertSame(99900, Plan::where('slug', 'silver')->sole()->price_paise);
        $this->assertSame(249900, Plan::where('slug', 'gold')->sole()->price_paise);
        $this->assertSame(499900, Plan::where('slug', 'platinum')->sole()->price_paise);
    }

    /**
     * The top tier should mean "everything" against the data actually seeded,
     * not an arbitrary ceiling.
     */
    public function test_platinum_covers_the_whole_seeded_catalogue(): void
    {
        $platinum = Plan::where('slug', 'platinum')->sole();

        $this->assertSame(Subcategory::count(), $platinum->quota->max_subcategories);
        $this->assertSame(Zone::whereNotNull('parent_id')->count(), $platinum->quota->max_zones);
        $this->assertSame(Category::count(), $platinum->quota->max_categories);
    }

    public function test_the_admin_seeder_still_runs_alongside(): void
    {
        $admin = User::where('role', UserRole::Admin)->sole();

        $this->assertTrue(Hash::check(env('ADMIN_PASSWORD', 'password'), $admin->password));
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_the_expected_cms_page_slugs_are_seeded(): void
    {
        $slugs = CmsPage::pluck('slug')->sort()->values()->all();

        $this->assertSame(['about', 'faq', 'privacy-policy', 'refund-policy', 'terms'], $slugs);
    }

    public function test_laravels_stub_test_user_is_not_seeded(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertSame(1, User::count());
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        // Keyed on slug, so a re-run must update in place rather than
        // duplicate — this seeder is intended to be safe on a live database.
        $before = [
            Category::count(), Subcategory::count(),
            Zone::count(), Plan::count(), User::count(), CmsPage::count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $after = [
            Category::count(), Subcategory::count(),
            Zone::count(), Plan::count(), User::count(), CmsPage::count(),
        ];

        $this->assertSame($before, $after);
    }
}
