<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Filament\Support\RecordStatus;
use App\Filament\Support\SafeDelete;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        // Delete goes through SafeDelete here too, never a bare
        // DeleteAction — the header is a separate registration from the
        // table, and a plain one here would skip the "unused only" check.
        return [
            RecordStatus::configure(
                Action::make('changeStatus'),
                fn (Category $record): string => CategoryResource::statusEffects($record),
            ),
            SafeDelete::configure(DeleteAction::make()),
        ];
    }

    public function getSubheading(): ?string
    {
        return RecordStatus::summary(
            $this->getRecord(),
            'shown in the customer app and selectable by salesmen and vendors.',
            'hidden from the apps, with all its subcategories. Existing subscriptions keep running.',
        );
    }
}
