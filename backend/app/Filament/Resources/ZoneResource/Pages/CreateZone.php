<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use Filament\Resources\Pages\CreateRecord;

class CreateZone extends CreateRecord
{
    protected static string $resource = ZoneResource::class;

    /**
     * Swaps the map's shape for the geometry expression.
     *
     * `polygon` is a spatial column, so it cannot be assigned as a plain
     * value — it has to go through ST_GeomFromText. See
     * ZoneResource::prepareBoundary() for how a circle becomes a polygon.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ZoneResource::prepareBoundary($data);
    }

    /**
     * Straight to the edit page: the Zone Areas tab only works on a saved
     * zone, so this is where an admin adding sub-zones wants to be next.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
