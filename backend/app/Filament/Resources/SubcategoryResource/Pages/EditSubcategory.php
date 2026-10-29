<?php

namespace App\Filament\Resources\SubcategoryResource\Pages;

use App\Filament\Resources\SubcategoryResource;
use App\Filament\Support\RecordStatus;
use App\Filament\Support\SafeDelete;
use App\Models\Subcategory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubcategory extends EditRecord
{
    protected static string $resource = SubcategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordStatus::configure(
                Action::make('changeStatus'),
                fn (Subcategory $record): string => SubcategoryResource::statusEffects($record),
            ),
            SafeDelete::configure(DeleteAction::make()),
        ];
    }

    public function getSubheading(): ?string
    {
        return RecordStatus::summary(
            $this->getRecord(),
            'customers can find vendors for it.',
            'hidden from the apps. Existing subscriptions keep running.',
        );
    }
}
