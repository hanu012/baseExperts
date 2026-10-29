<?php

namespace App\Filament\Resources\DocumentTypeResource\Pages;

use App\Filament\Resources\DocumentTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListDocumentTypes extends ListRecords
{
    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getSubheading(): HtmlString
    {
        return new HtmlString(
            'The KYC documents vendors upload. A vendor account can be approved only when every '
            .'<strong>compulsory</strong>, active type is <strong>Verified</strong>. Drag to set the order '
            .'vendors see. Types are <strong>switched off, not deleted</strong> — uploads are kept.'
        );
    }
}
