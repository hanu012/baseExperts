<?php

namespace App\Filament\SuperAdmin\Resources\ActivityLogResource\Pages;

use App\Filament\SuperAdmin\Resources\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Every recorded change, newest first. Read-only — entries cannot be edited or deleted. Passwords and secrets are never recorded.';
    }
}
