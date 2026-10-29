<?php

namespace App\Filament\Resources\MediaGalleryResource\Pages;

use App\Filament\Resources\MediaGalleryResource;
use Filament\Resources\Pages\ListRecords;

class ListMediaGallery extends ListRecords
{
    protected static string $resource = MediaGalleryResource::class;

    protected static ?string $title = 'Media Gallery';

    /** Totals across the whole gallery, so the admin knows the scale before filtering. */
    public function getSubheading(): ?string
    {
        $counts = MediaGalleryResource::getEloquentQuery()
            ->toBase()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return sprintf(
            '%d photos · %d videos. Uploads go live without review; use Take down to hide one from customers.',
            (int) ($counts['image'] ?? 0),
            (int) ($counts['video'] ?? 0),
        );
    }
}
