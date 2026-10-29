<?php

namespace App\Services;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a customer's location to a zone (SPEC section 4.2/8, task 4.6)
 * — either from a GPS point or a pincode fallback when GPS is denied or
 * matches nothing.
 *
 * Both lookup paths share matchableZones() so they can't drift apart on
 * what counts as matchable — the leaf-only and effective-active rules are
 * written once, not duplicated per method.
 */
class ZoneMatcher
{
    public function matchPoint(float $lat, float $lng): ?Zone
    {
        return $this->matchableZones()
            ->whereRaw('ST_Contains(polygon, ST_GeomFromText(?, 4326))', ["POINT({$lng} {$lat})"])
            ->first();
    }

    public function matchPincode(string $pincode): ?Zone
    {
        return $this->matchableZones()->where('pincode', $pincode)->first();
    }

    /**
     * SPEC section 8: only leaf zones (no children) ever match, and a leaf
     * is matchable only when `is_active AND (parent_id IS NULL OR
     * parent.is_active)` — computed here at match time, never physically
     * cascaded onto child rows.
     */
    private function matchableZones(): Builder
    {
        return Zone::query()
            ->whereDoesntHave('children')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('parent_id')
                ->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)));
    }
}
