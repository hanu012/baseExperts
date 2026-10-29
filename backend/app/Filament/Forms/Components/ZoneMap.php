<?php

namespace App\Filament\Forms\Components;

use App\Models\Zone;
use App\Support\MapKeys;
use Filament\Forms\Components\Field;
use Illuminate\Support\Str;

/**
 * Google Maps zone editor: a polygon drawn point by point, or a circle set
 * by a draggable centre and a radius.
 *
 * The field's own state is the polygon ring — an array of
 * `['lat' => float, 'lng' => float]` points, explicit keys so the lat/lng
 * order cannot be transposed on the way to Zone::polygonExpression(). The
 * circle lives in the sibling `shape_type`, `center_lat`, `center_lng` and
 * `radius_km` fields, which the map reads and writes through Livewire
 * entanglement so the inputs below the map and the map itself stay in step.
 *
 * Google's Drawing library was retired in 2026, so drawing is done here on
 * plain click events rather than through DrawingManager.
 */
class ZoneMap extends Field
{
    protected string $view = 'filament.forms.components.zone-map';

    protected int|\Closure|null $height = null;

    public function height(int|\Closure $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getHeight(): int
    {
        return $this->evaluate($this->height) ?? 460;
    }

    /**
     * The referrer-restricted browser key (Settings → Maps, else .env).
     * Distinct from the server-side Places key, which is never rendered.
     */
    public function getBrowserKey(): ?string
    {
        return MapKeys::browserKey();
    }

    /**
     * Whether the search box is shown.
     *
     * Place search is proxied through PlaceLookupController with the
     * server key; with no key configured there is nothing to search, so the
     * box is hidden rather than shown and broken.
     */
    public function hasPlaceSearch(): bool
    {
        return MapKeys::placesKey() !== null;
    }

    /**
     * State path of a field beside this one in the same form, for the map
     * to entangle with.
     */
    public function getSiblingStatePath(string $name): string
    {
        return Str::beforeLast($this->getStatePath(), '.').'.'.$name;
    }

    /**
     * Where the map opens when there is nothing drawn yet.
     *
     * Named getMapCenter, not getDefaultView: Filament's ViewComponent already
     * declares getDefaultView(): ?string for the Blade view name, and
     * overriding it with a different return type is a fatal error.
     *
     * @return array{lat: float, lng: float, zoom: int}
     */
    public function getMapCenter(): array
    {
        return [
            'lat' => (float) config('map.default_latitude'),
            'lng' => (float) config('map.default_longitude'),
            'zoom' => (int) config('map.default_zoom'),
        ];
    }

    /**
     * Every other zone, drawn faintly under the one being edited so a new
     * boundary can be placed against its neighbours instead of blind.
     *
     * @return array<int, array{id: int, label: string, active: bool, points: array<int, array{lat: float, lng: float}>}>
     */
    public function getOtherZones(): array
    {
        $record = $this->getRecord();

        return Zone::query()
            ->select(['id', 'name', 'code', 'is_active'])
            ->selectRaw('ST_AsText(polygon) as polygon_wkt')
            ->when($record instanceof Zone, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->orderBy('name')
            ->get()
            ->map(fn (Zone $zone): array => [
                'id' => $zone->getKey(),
                'label' => $zone->code ? "{$zone->name} · {$zone->code}" : $zone->name,
                'active' => $zone->is_active,
                'points' => Zone::pointsFromWkt($zone->polygon_wkt),
            ])
            ->all();
    }
}
