<?php

namespace Tests\Feature\Schema;

use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The lat/lng swap between Leaflet and WKT is the one silent failure in this
 * feature: transposed coordinates still produce a valid polygon that saves
 * without error, just in the wrong hemisphere. These tests pin the order with
 * real coordinates and a real ST_Contains.
 */
class ZonePolygonTest extends TestCase
{
    use RefreshDatabase;

    /** Roughly the Gota area of Ahmedabad. */
    private const GOTA = [
        ['lat' => 23.09, 'lng' => 72.52],
        ['lat' => 23.09, 'lng' => 72.58],
        ['lat' => 23.13, 'lng' => 72.58],
        ['lat' => 23.13, 'lng' => 72.52],
    ];

    private function wktOf(Zone $zone): string
    {
        return DB::table('zones')
            ->selectRaw('ST_AsText(polygon) as wkt')
            ->where('id', $zone->getKey())
            ->value('wkt');
    }

    public function test_wkt_is_written_lng_first(): void
    {
        // WKT is "X Y" = "lng lat". If this ever reads "23.09 72.52" the
        // polygon has been transposed into the Arabian Sea.
        $wkt = Zone::polygonWktFrom(self::GOTA);

        $this->assertStringStartsWith('POLYGON((72.52000000 23.09000000', $wkt);
    }

    public function test_the_ring_is_closed_automatically(): void
    {
        $wkt = Zone::polygonWktFrom(self::GOTA);

        preg_match('/^POLYGON\(\((.*)\)\)$/', $wkt, $m);
        $points = explode(',', $m[1]);

        $this->assertSame($points[0], end($points));
        // Four corners plus the repeated closing point.
        $this->assertCount(5, $points);
    }

    public function test_a_saved_zone_contains_a_point_inside_it(): void
    {
        $zone = Zone::factory()->withBoundary(self::GOTA)->create();

        $contains = DB::table('zones')
            ->selectRaw(
                'ST_Contains(polygon, ST_GeomFromText(?, 4326)) as inside',
                ['POINT(72.55 23.11)']
            )
            ->where('id', $zone->getKey())
            ->value('inside');

        $this->assertSame(1, (int) $contains);
    }

    public function test_a_saved_zone_excludes_a_point_outside_it(): void
    {
        $zone = Zone::factory()->withBoundary(self::GOTA)->create();

        $contains = DB::table('zones')
            ->selectRaw(
                'ST_Contains(polygon, ST_GeomFromText(?, 4326)) as inside',
                ['POINT(72.90 23.50)']
            )
            ->where('id', $zone->getKey())
            ->value('inside');

        $this->assertSame(0, (int) $contains);
    }

    public function test_the_stored_geometry_keeps_srid_4326(): void
    {
        $zone = Zone::factory()->create();

        $srid = DB::table('zones')
            ->selectRaw('ST_SRID(polygon) as srid')
            ->where('id', $zone->getKey())
            ->value('srid');

        $this->assertSame(4326, (int) $srid);
    }

    public function test_points_round_trip_through_storage(): void
    {
        $zone = Zone::factory()->withBoundary(self::GOTA)->create();

        $points = Zone::pointsFromWkt($this->wktOf($zone));

        $this->assertCount(4, $points);
        $this->assertEqualsWithDelta(23.09, $points[0]['lat'], 0.0000001);
        $this->assertEqualsWithDelta(72.52, $points[0]['lng'], 0.0000001);
    }

    public function test_too_few_points_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Zone::polygonWktFrom([
            ['lat' => 23.09, 'lng' => 72.52],
            ['lat' => 23.10, 'lng' => 72.53],
        ]);
    }

    public function test_out_of_range_latitude_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Zone::polygonWktFrom(ZoneFactory::square(95.0, 72.5));
    }

    public function test_non_numeric_coordinates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // The injection shape: a string that would be dangerous if it reached
        // SQL. It never does — it fails the numeric check first.
        Zone::polygonWktFrom([
            ['lat' => "23.09') --", 'lng' => 72.52],
            ['lat' => 23.10, 'lng' => 72.53],
            ['lat' => 23.11, 'lng' => 72.54],
        ]);
    }

    public function test_malformed_wkt_parses_to_no_points(): void
    {
        $this->assertSame([], Zone::pointsFromWkt('not a polygon'));
        $this->assertSame([], Zone::pointsFromWkt(null));
    }

    public function test_a_new_zone_is_a_draft(): void
    {
        // SPEC section 11: draw rough, refine, then activate.
        $zone = Zone::factory()->create();

        $this->assertFalse($zone->is_active);
    }
}
