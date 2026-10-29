<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use App\Filament\Support\RecordStatus;
use App\Filament\Support\SafeDelete;
use App\Models\Zone;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordStatus::configure(
                Action::make('changeStatus'),
                fn (Zone $record): string => ZoneResource::statusEffects($record),
                'Draft',
            ),
            SafeDelete::configure(DeleteAction::make()),
        ];
    }

    public function getSubheading(): ?string
    {
        return RecordStatus::summary(
            $this->getRecord(),
            'customer locations inside this boundary are matched to vendors.',
            'not matched to customers and not offered for selection.',
            'Draft',
        );
    }

    /**
     * Loads the stored boundary back into the map.
     *
     * Selecting `polygon` through Eloquent returns binary, so it is read as
     * WKT with ST_AsText and parsed back into {lat, lng} points.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $wkt = DB::table('zones')
            ->selectRaw('ST_AsText(polygon) as wkt')
            ->where('id', $this->getRecord()->getKey())
            ->value('wkt');

        $data['polygon_points'] = Zone::pointsFromWkt($wkt);

        return $data;
    }

    /**
     * Same conversion as CreateZone — see the note there.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ZoneResource::prepareBoundary($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
