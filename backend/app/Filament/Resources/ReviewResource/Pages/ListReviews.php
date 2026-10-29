<?php

namespace App\Filament\Resources\ReviewResource\Pages;

use App\Filament\Resources\ReviewResource;
use App\Models\Review;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        // No CreateAction — reviews aren't authored through the admin panel.
        return [];
    }

    /**
     * Totals across every review, so the admin sees the overall picture
     * before filtering. The average counts visible reviews only, the same
     * ones a vendor's public rating is built from.
     */
    public function getSubheading(): ?string
    {
        $total = Review::query()->count();
        $hidden = Review::query()->where('is_hidden', true)->count();
        $unreplied = Review::query()->where('is_hidden', false)->whereNull('vendor_reply')->count();
        $average = Review::query()->where('is_hidden', false)->avg('rating');

        return sprintf(
            '%d reviews · average %s ★ · %d hidden · %d visible without a vendor reply',
            $total,
            $average === null ? '—' : number_format((float) $average, 1),
            $hidden,
            $unreplied,
        );
    }
}
