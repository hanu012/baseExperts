<?php

namespace App\Models;

use App\Models\Concerns\PreventsDeletionWhileSubscribed;
use App\Support\Languages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A geographic zone (SPEC section 8). Self-referencing: a main zone such as
 * Ahmedabad contains sub-zones like Gota, Ranip and Sola.
 *
 * The `polygon` column is raw geometry. Always write it through
 * ST_GeomFromText(..., 4326) — MariaDB 10.4 cannot declare the SRID on the
 * column, so it only exists on the values. See the zones migration.
 */
class Zone extends Model
{
    use HasFactory;
    use PreventsDeletionWhileSubscribed;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'code',
        'polygon',
        'shape_type',
        'center_lat',
        'center_lng',
        'radius_km',
        'pincode',
        'is_active',
    ];

    /** Drawn as a free-form ring of points. */
    public const SHAPE_POLYGON = 'polygon';

    /** Drawn as a centre and radius; `polygon` holds its approximation. */
    public const SHAPE_CIRCLE = 'circle';

    /** Points used to approximate a circle in the `polygon` column. */
    public const CIRCLE_SEGMENTS = 64;

    /**
     * Geometry is binary and meaningless to the app layer; it is only ever
     * used inside spatial SQL. Hiding it keeps it out of API responses and
     * out of accidental JSON casts.
     */
    protected $hidden = ['polygon'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'radius_km' => 'float',
        ];
    }

    /**
     * Gives a zone saved without a code a default one, ZONE-007 style.
     *
     * Done after the INSERT because the default is built from the id. The
     * write is a query-builder update, not save(), so it fires no second
     * round of model events (audit log, deletion guards) for what is
     * really part of creating the row.
     */
    protected static function booted(): void
    {
        static::created(function (Zone $zone) {
            if (filled($zone->code)) {
                return;
            }

            $code = sprintf('ZONE-%03d', $zone->getKey());

            // An admin may already have typed this exact code by hand.
            for ($suffix = 2; static::where('code', $code)->exists(); $suffix++) {
                $code = sprintf('ZONE-%03d-%d', $zone->getKey(), $suffix);
            }

            static::query()->whereKey($zone->getKey())->update(['code' => $code]);
            $zone->code = $code;
            $zone->syncOriginalAttribute('code');
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Zone::class, 'parent_id');
    }

    public function subscriptionItemType(): string
    {
        return 'zone';
    }

    /**
     * A zone with no children — the only kind that matches or counts against
     * quota (SPEC section 8, leaf-only matching). Parent zones exist for
     * navigation and grouping.
     */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Every zone beneath this one, at any depth.
     *
     * Walks level by level rather than recursing per node, so the whole
     * subtree costs one query per level instead of one per zone. The seen-set
     * also stops a cycle (a zone somehow made its own ancestor) from looping
     * forever — `parent_id` has no constraint preventing that.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $found = [];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $found)
                ->pluck('id')
                ->all();

            if ($children === []) {
                break;
            }

            $found = array_merge($found, $children);
            $frontier = $children;
        }

        return $found;
    }

    /**
     * A parent's "in use" count includes every descendant's subscriptions.
     *
     * SPEC section 8: deactivating a busy parent silently drops every
     * descendant out of matching, because effective active status is
     * `own is_active AND (no parent OR parent.is_active)`. So the parent's own
     * row being unreferenced says nothing about the damage — the warning has
     * to reflect the subtree. Same pattern as Category counting its
     * subcategories.
     *
     * @return array<int, array{type: string, ids: array<int, int>}>
     */
    protected function additionalSubscriptionReferences(): array
    {
        return [[
            'type' => 'zone',
            'ids' => $this->descendantIds(),
        ]];
    }

    /**
     * The vendors whose subscriptions cover this zone, for the admin's
     * "In use" breakdown.
     *
     * Counts the subtree, matching countSubscriptionReferences(): a
     * parent zone's selections live on its children, so listing only
     * direct hits would show an empty list next to a non-zero count.
     *
     * Returns rows rather than models — the caller wants one flat line
     * per subscription (who, which zone, which plan, still live?), and
     * hydrating Vendor models to read four columns off each would be
     * more machinery for less.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function subscribingVendors(): \Illuminate\Support\Collection
    {
        $zoneIds = array_merge([$this->getKey()], $this->descendantIds());

        return DB::table('subscription_items')
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_items.subscription_id')
            ->join('vendors', 'vendors.id', '=', 'subscriptions.vendor_id')
            ->leftJoin('zones', 'zones.id', '=', 'subscription_items.item_id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscription_items.item_type', 'zone')
            ->whereIn('subscription_items.item_id', $zoneIds)
            // Soft-deleted subscriptions and vendors are not "in use" by
            // anyone, and showing them would overstate the cost of
            // deactivating this zone.
            ->whereNull('subscriptions.deleted_at')
            ->whereNull('vendors.deleted_at')
            ->select([
                'vendors.id as vendor_id',
                // The admin links to the owning user, not the vendor:
                // vendor management was folded into UserResource, which
                // is the only one of the two with an edit page.
                'vendors.user_id',
                'vendors.business_name',
                'vendors.status as vendor_status',
                'zones.name as zone_name',
                'plans.name as plan_name',
                'subscriptions.status as subscription_status',
                'subscriptions.end_date',
            ])
            ->orderBy('vendors.business_name')
            ->get()
            // plans.name is stored per language; these are raw rows.
            ->each(fn (object $row) => $row->plan_name = Languages::pick($row->plan_name));
    }

    /**
     * Why this cannot be deleted right now, or null when it can.
     *
     * parent_id is ON DELETE SET NULL, so deleting a main zone with
     * sub-zones would quietly turn each of them into a main zone. Refused
     * instead: delete the sub-zones first. Leads keep their row with
     * zone_id set to null, which is fine for history.
     */
    public function deletionBlocker(): ?string
    {
        $children = $this->children()->count();

        if ($children > 0) {
            return "Has {$children} ".\Illuminate\Support\Str::plural('sub-zone', $children).'. Delete them first.';
        }

        $subscriptions = $this->countSubscriptionReferences();

        if ($subscriptions > 0) {
            return "Covered by {$subscriptions} ".\Illuminate\Support\Str::plural('subscription', $subscriptions).'. Set it to Draft instead.';
        }

        return null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Adds the polygon back as WKT, since selecting the raw geometry column
     * returns binary that is useless to the app layer.
     */
    public function scopeWithPolygonWkt($query)
    {
        return $query->addSelect(['*'])
            ->selectRaw('ST_AsText(polygon) as polygon_wkt');
    }

    /**
     * Builds the SQL expression that writes this zone's boundary.
     *
     * COORDINATE ORDER — the one thing to get right in this file.
     *
     * WKT is "lng lat" (X then Y). The admin map hands back lat/lng, and GeoJSON
     * uses [lng, lat]. Swapping them produces a polygon that is still
     * geometrically valid and still saves without error — it just sits in the
     * Gulf of Guinea instead of Ahmedabad, and nothing complains until a
     * customer search silently matches no vendors.
     *
     * So the swap happens here and nowhere else. Input is an array of points
     * with explicit keys, never bare pairs, so a caller cannot transpose them
     * by accident.
     *
     * SQL SAFETY: the returned expression carries no caller-supplied string.
     * Every coordinate is validated as a number and re-formatted with
     * sprintf, so the WKT handed to ST_GeomFromText contains only digits,
     * signs, dots, commas, spaces and parentheses.
     *
     * @param  array<int, array{lat: float|string, lng: float|string}>  $points
     *
     * @throws \InvalidArgumentException
     */
    public static function polygonExpression(array $points): Expression
    {
        return DB::raw("ST_GeomFromText('".static::polygonWktFrom($points)."', 4326)");
    }

    /**
     * Validates a ring and renders it as WKT.
     *
     * @param  array<int, array{lat: float|string, lng: float|string}>  $points
     *
     * @throws \InvalidArgumentException
     */
    public static function polygonWktFrom(array $points): string
    {
        $points = array_values($points);

        if (count($points) < 3) {
            throw new InvalidArgumentException(
                'A zone boundary needs at least 3 distinct points.'
            );
        }

        $ring = [];

        foreach ($points as $index => $point) {
            if (! isset($point['lat'], $point['lng']) || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])) {
                throw new InvalidArgumentException(
                    "Point {$index} is missing a numeric lat/lng."
                );
            }

            $lat = (float) $point['lat'];
            $lng = (float) $point['lng'];

            if ($lat < -90 || $lat > 90) {
                throw new InvalidArgumentException("Latitude {$lat} is out of range.");
            }

            if ($lng < -180 || $lng > 180) {
                throw new InvalidArgumentException("Longitude {$lng} is out of range.");
            }

            // "lng lat" — X then Y. See the note above.
            $ring[] = sprintf('%.8F %.8F', $lng, $lat);
        }

        // A WKT ring must close: last point identical to the first. The map
        // does not repeat it, so close it here rather than asking every
        // caller to remember.
        if ($ring[0] !== end($ring)) {
            $ring[] = $ring[0];
        }

        return 'POLYGON(('.implode(',', $ring).'))';
    }

    /**
     * A ring of points approximating a circle, for the `polygon` column.
     *
     * Each point is the great-circle destination from the centre at the
     * given bearing, so the ring is a true circle on the ground rather
     * than one in degrees, which would come out as an east-west-squashed
     * oval away from the equator.
     *
     * @return array<int, array{lat: float, lng: float}>
     *
     * @throws \InvalidArgumentException
     */
    public static function circlePoints(float $lat, float $lng, float $radiusKm, int $segments = self::CIRCLE_SEGMENTS): array
    {
        if ($radiusKm <= 0) {
            throw new InvalidArgumentException('A circle zone needs a radius above zero.');
        }

        $earthRadiusKm = 6371.0088;
        $angular = $radiusKm / $earthRadiusKm;
        $lat1 = deg2rad($lat);
        $lng1 = deg2rad($lng);

        $points = [];

        for ($i = 0; $i < $segments; $i++) {
            $bearing = 2 * M_PI * $i / $segments;

            $lat2 = asin(sin($lat1) * cos($angular) + cos($lat1) * sin($angular) * cos($bearing));
            $lng2 = $lng1 + atan2(
                sin($bearing) * sin($angular) * cos($lat1),
                cos($angular) - sin($lat1) * sin($lat2),
            );

            $points[] = ['lat' => rad2deg($lat2), 'lng' => rad2deg($lng2)];
        }

        return $points;
    }

    /**
     * Turns stored WKT back into points for the map field.
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function pointsFromWkt(?string $wkt): array
    {
        if ($wkt === null || ! preg_match('/^POLYGON\(\((.*)\)\)$/i', trim($wkt), $matches)) {
            return [];
        }

        $points = [];

        foreach (explode(',', $matches[1]) as $pair) {
            $parts = preg_split('/\s+/', trim($pair));

            if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                continue;
            }

            // Reverse of the write path: WKT is "lng lat".
            $points[] = ['lat' => (float) $parts[1], 'lng' => (float) $parts[0]];
        }

        // Drop the repeated closing point — the map draws an open ring.
        if (count($points) > 1 && $points[0] == end($points)) {
            array_pop($points);
        }

        return $points;
    }
}
