<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Resources\VendorResource;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    /**
     * No Create action: a vendor is created with its User and usually a
     * subscription in one transaction (VendorDraftService), not from a bare
     * Filament form. See the resource's docblock.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
