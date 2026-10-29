<?php

namespace App\Support;

/**
 * Straight-line (Haversine) distance between two points, in kilometres.
 *
 * Originally lived only in VendorDetailController, with a note to extract
 * it "if a second caller ever needs it" — VendorSearchService now does
 * (SPEC section 4 item 4's "Nearest" sort, task 5.3), so here it is.
 *
 * Also provides the equivalent raw SQL expression, for the one place this
 * needs to run inside a query rather than on a fetched row: sorting a
 * paginated result by distance has to happen in the database, since PHP
 * only ever sees the one page the database already chose.
 */
class GeoDistance
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 1);
    }

    /**
     * A raw Haversine expression against `$latColumn`/`$lngColumn`,
     * parameterised on the caller's own point. Standard great-circle
     * formula — not MariaDB's ST_Distance_Sphere, which this project's
     * MariaDB 10.4 (CLAUDE.md) does not have; that landed in 10.5.
     *
     * @return array{0: string, 1: array<int, float>} the expression and
     *     its bindings, in the order the `?` placeholders appear
     */
    public static function sqlExpression(string $latColumn, string $lngColumn, float $lat, float $lng): array
    {
        $expression = sprintf(
            // Clamped to [-1, 1]: rounding can push the argument just past
            // 1 for a point at the exact same spot, and acos() of that is
            // NULL rather than 0.
            '%s * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(%s)) * cos(radians(%s) - radians(?)) + sin(radians(?)) * sin(radians(%s)))))',
            self::EARTH_RADIUS_KM,
            $latColumn,
            $lngColumn,
            $latColumn,
        );

        return [$expression, [$lat, $lng, $lat]];
    }
}
