<?php

namespace App\Filament\Resources\SubZoneResource\Pages;

use App\Filament\Resources\SubZoneResource;
use App\Filament\Resources\ZoneResource\Pages\CreateZone;

class CreateSubZone extends CreateZone
{
    protected static string $resource = SubZoneResource::class;

    /** Back to the list: a sub-zone has no Zone Areas tab to continue on. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
