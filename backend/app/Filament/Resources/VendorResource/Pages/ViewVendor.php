<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Resources\VendorResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    /**
     * No Edit action: services/zones are quota-governed and approval lives
     * in the verification queue. See the resource's docblock.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
