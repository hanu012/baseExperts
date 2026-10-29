<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use App\Filament\Support\RecordStatus;
use App\Models\Zone;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListZones extends ListRecords
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** All / Active / Draft, each with its count — for this screen's rows only. */
    public function getTabs(): array
    {
        $resource = static::getResource();

        return RecordStatus::tabs(
            Zone::class,
            $resource::isSubZoneScreen() ? 'sub-zones' : 'zones',
            'Draft',
            fn ($query) => $resource::scopeToScreen($query),
        );
    }

    /**
     * States both rules an admin will otherwise trip over: delete only works
     * on an unused zone, and new zones start as drafts.
     */
    public function getSubheading(): ?HtmlString
    {
        return new HtmlString(
            'A zone can be <strong>deleted only while unused</strong> — no sub-zones and no '
            .'subscriptions. Otherwise set it to Draft with <em>Change status</em>. '
            .'New zones start as <strong>drafts</strong>: draw a rough boundary now, refine it, '
            .'then make it Active. Only active zones are matched against customer locations.'
        );
    }
}
