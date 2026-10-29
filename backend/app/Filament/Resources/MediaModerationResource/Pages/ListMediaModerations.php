<?php

namespace App\Filament\Resources\MediaModerationResource\Pages;

use App\Filament\Resources\MediaModerationResource;
use Filament\Resources\Pages\ListRecords;

class ListMediaModerations extends ListRecords
{
    protected static string $resource = MediaModerationResource::class;

    protected function getHeaderActions(): array
    {
        // No CreateAction — media rows aren't created through this queue.
        return [];
    }
}
