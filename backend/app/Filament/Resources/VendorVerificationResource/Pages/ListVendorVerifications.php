<?php

namespace App\Filament\Resources\VendorVerificationResource\Pages;

use App\Filament\Resources\VendorVerificationResource;
use Filament\Resources\Pages\ListRecords;

class ListVendorVerifications extends ListRecords
{
    protected static string $resource = VendorVerificationResource::class;

    protected function getHeaderActions(): array
    {
        // No CreateAction — vendors aren't created through this queue.
        return [];
    }
}
