<?php

namespace App\Filament\Resources\SubcategoryResource\Pages;

use App\Filament\Resources\SubcategoryResource;
use App\Filament\Support\RecordStatus;
use App\Models\Subcategory;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubcategories extends ListRecords
{
    protected static string $resource = SubcategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** All / Active / Inactive, each with its count. */
    public function getTabs(): array
    {
        return RecordStatus::tabs(Subcategory::class, 'subcategories');
    }

    public function getSubheading(): ?string
    {
        return 'A subcategory can be deleted only while no subscription or lead uses it. Otherwise use Change status to hide it.';
    }
}
