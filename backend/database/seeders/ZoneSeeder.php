<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ahmedabad and its sub-zones (SPEC sections 5.3, 8 and 11).
 *
 * SHAPE: exactly two levels — Ahmedabad as the parent, 15 sub-zones beneath
 * it — matching SPEC section 8's depth cap. Only the 15 sub-zones are leaves,
 * so only they participate in matching or count against a plan's zone quota.
 * Ahmedabad exists for grouping.
 *
 * AHMEDABAD IS SEEDED ACTIVE, and that is load-bearing rather than
 * incidental: effective active status is
 * `own is_active AND (no parent OR parent.is_active)`, so leaving the parent
 * inactive would make all 15 sub-zones unmatchable while each individually
 * looked fine in the admin panel.
 *
 * COORDINATES are approximate centroids of the real areas, drawn as small
 * non-overlapping boxes (~0.01 degrees, roughly 1.1 km) rather than surveyed
 * boundaries. Non-overlapping is deliberate: two polygons covering the same
 * point would match one customer to two zones and quietly distort both search
 * results and the lead records written against them. Replace with real
 * boundaries drawn through the admin map before launch.
 */
class ZoneSeeder extends Seeder
{
    /** Half-width of each sub-zone box, in degrees. */
    private const BOX = 0.005;

    /**
     * name => [latitude, longitude, pincode]
     *
     * @var array<string, array{0: float, 1: float, 2: string}>
     */
    private const SUB_ZONES = [
        'Gota' => [23.102, 72.545, '382481'],
        'Chandkheda' => [23.112, 72.581, '382424'],
        'Ranip' => [23.075, 72.573, '382480'],
        'Sola' => [23.077, 72.517, '380060'],
        'Naranpura' => [23.055, 72.560, '380013'],
        'Thaltej' => [23.050, 72.507, '380059'],
        'Bodakdev' => [23.038, 72.512, '380054'],
        'Navrangpura' => [23.036, 72.560, '380009'],
        'Vastrapur' => [23.036, 72.529, '380015'],
        'Bopal' => [23.030, 72.470, '380058'],
        'Satellite' => [23.021, 72.523, '380015'],
        'Ambawadi' => [23.013, 72.552, '380015'],
        'Paldi' => [23.011, 72.564, '380007'],
        'Maninagar' => [22.996, 72.601, '380008'],
        'Sarkhej' => [22.982, 72.500, '382210'],
    ];

    public function run(): void
    {
        $ahmedabad = Zone::updateOrCreate(
            ['parent_id' => null, 'slug' => 'ahmedabad'],
            [
                'name' => 'Ahmedabad',
                // Encloses every sub-zone below.
                'polygon' => Zone::polygonExpression(self::box(23.05, 72.55, 0.10)),
                'pincode' => '380001',
                // Must be active, or every child stops matching. See above.
                'is_active' => true,
            ],
        );

        foreach (self::SUB_ZONES as $name => [$latitude, $longitude, $pincode]) {
            Zone::updateOrCreate(
                ['parent_id' => $ahmedabad->id, 'slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'polygon' => Zone::polygonExpression(self::box($latitude, $longitude, self::BOX)),
                    'pincode' => $pincode,
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info(
            'Zones: 1 parent (Ahmedabad) + '.count(self::SUB_ZONES).' active sub-zones.'
        );
    }

    /**
     * An axis-aligned square centred on the given point.
     *
     * Points are {lat, lng} with explicit keys — Zone::polygonExpression()
     * does the swap into WKT's lng-first order, and nothing else should.
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private static function box(float $latitude, float $longitude, float $half): array
    {
        return [
            ['lat' => $latitude - $half, 'lng' => $longitude - $half],
            ['lat' => $latitude - $half, 'lng' => $longitude + $half],
            ['lat' => $latitude + $half, 'lng' => $longitude + $half],
            ['lat' => $latitude + $half, 'lng' => $longitude - $half],
        ];
    }

    /**
     * Exposed so tests can assert containment against the same coordinates
     * the seeder used, rather than duplicating them.
     *
     * @return array<string, array{0: float, 1: float, 2: string}>
     */
    public static function subZones(): array
    {
        return self::SUB_ZONES;
    }
}
