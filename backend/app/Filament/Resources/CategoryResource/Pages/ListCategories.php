<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Filament\Support\RecordStatus;
use App\Models\Category;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** All / Active / Inactive, each with its count. */
    public function getTabs(): array
    {
        return RecordStatus::tabs(Category::class, 'categories');
    }

    /**
     * States the no-delete rule where an admin would otherwise go looking for
     * the missing button, and gives the reason rather than just the rule.
     */
    public function getSubheading(): ?HtmlString
    {
        return new HtmlString(
            'A category can be <strong>deleted only while unused</strong> — no subcategories '
            .'and no subscriptions. Once vendors have bought it, use <em>Change status</em> '
            .'to hide it from the apps instead; existing subscriptions keep working.'
        );
    }
}
