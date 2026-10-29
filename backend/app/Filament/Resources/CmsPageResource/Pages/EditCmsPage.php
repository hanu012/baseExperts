<?php

namespace App\Filament\Resources\CmsPageResource\Pages;

use App\Filament\Resources\CmsPageResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCmsPage extends EditRecord
{
    protected static string $resource = CmsPageResource::class;

    protected function getHeaderActions(): array
    {
        // No DeleteAction — see CmsPageResource's own docblock.
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();

        if (($data['is_published'] ?? false) && empty($this->getRecord()->published_at) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return $data;
    }
}
