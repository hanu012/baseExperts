<?php

namespace Tests\Feature\Services;

use App\Models\Zone;
use App\Services\ZoneMatcher;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zone point/pincode lookup (SPEC section 4.2/8, task 4.6) — leaf-only
 * matching and the effective-active expression
 * (`is_active AND (parent_id IS NULL OR parent.is_active)`), computed at
 * match time, never physically cascaded.
 */
class ZoneMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ZoneMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ZoneMatcher();
    }

    public function test_a_point_inside_a_standalone_active_leaf_matches(): void
    {
        $zone = Zone::factory()->active()->withBoundary(ZoneFactory::square(23.0, 72.5))->create();

        $match = $this->matcher->matchPoint(23.02, 72.52);

        $this->assertSame($zone->id, $match?->id);
    }

    public function test_a_point_outside_any_zone_matches_nothing(): void
    {
        Zone::factory()->active()->withBoundary(ZoneFactory::square(23.0, 72.5))->create();

        $match = $this->matcher->matchPoint(10.0, 10.0);

        $this->assertNull($match);
    }

    public function test_a_point_inside_both_parent_and_child_matches_the_child_not_the_parent(): void
    {
        // Parent's boundary is large enough to geometrically contain the
        // child's — a point inside the child is also inside the parent, so
        // this proves leaf-only matching actually picks the child, not
        // just that a match happens at all.
        $parent = Zone::factory()->active()
            ->withBoundary(ZoneFactory::square(23.0, 72.5, size: 0.4))
            ->create();

        $child = Zone::factory()->active()
            ->withBoundary(ZoneFactory::square(23.05, 72.55, size: 0.04))
            ->create(['parent_id' => $parent->id]);

        $match = $this->matcher->matchPoint(23.06, 72.56);

        $this->assertSame($child->id, $match?->id);
    }

    public function test_an_active_leaf_with_an_inactive_parent_does_not_match(): void
    {
        $parent = Zone::factory() // inactive by default
            ->withBoundary(ZoneFactory::square(23.0, 72.5, size: 0.4))
            ->create();

        Zone::factory()->active()
            ->withBoundary(ZoneFactory::square(23.05, 72.55, size: 0.04))
            ->create(['parent_id' => $parent->id]);

        $match = $this->matcher->matchPoint(23.06, 72.56);

        $this->assertNull($match);
    }

    public function test_an_inactive_leaf_with_an_active_parent_does_not_match(): void
    {
        $parent = Zone::factory()->active()
            ->withBoundary(ZoneFactory::square(23.0, 72.5, size: 0.4))
            ->create();

        Zone::factory() // inactive by default
            ->withBoundary(ZoneFactory::square(23.05, 72.55, size: 0.04))
            ->create(['parent_id' => $parent->id]);

        $match = $this->matcher->matchPoint(23.06, 72.56);

        $this->assertNull($match);
    }

    public function test_pincode_matches_an_active_leaf_zone(): void
    {
        $zone = Zone::factory()->active()->create(['pincode' => '380001']);

        $match = $this->matcher->matchPincode('380001');

        $this->assertSame($zone->id, $match?->id);
    }

    public function test_pincode_does_not_match_an_inactive_zone(): void
    {
        Zone::factory()->create(['pincode' => '380001']); // inactive by default

        $this->assertNull($this->matcher->matchPincode('380001'));
    }

    public function test_pincode_does_not_match_when_the_zones_parent_is_inactive(): void
    {
        $parent = Zone::factory()->create(['pincode' => null]); // inactive by default

        Zone::factory()->active()->create(['parent_id' => $parent->id, 'pincode' => '380001']);

        $this->assertNull($this->matcher->matchPincode('380001'));
    }

    public function test_pincode_matches_nothing_when_no_zone_has_it(): void
    {
        Zone::factory()->active()->create(['pincode' => '380001']);

        $this->assertNull($this->matcher->matchPincode('999999'));
    }
}
