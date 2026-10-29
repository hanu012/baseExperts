<?php

namespace App\Filament\SuperAdmin\Resources\AdminResource\Pages;

use App\Filament\SuperAdmin\Resources\AdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdmins extends ListRecords
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New admin'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Admins work in the Admin panel with the permissions ticked here. Super-admins can do everything and are the only ones who can open this panel. '
            .'There is always at least one super-admin, and nobody can change or delete their own account here.';
    }
}
