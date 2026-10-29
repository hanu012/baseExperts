<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubZoneResource\Pages;

/**
 * Sub-zones (areas inside a main zone, e.g. Gota inside Ahmedabad) as their
 * own screen. Same `zones` table and same form as ZoneResource — it only
 * narrows the list to rows with a parent and makes the parent required.
 * See ZoneResource for the shared rules (two levels deep, drafts, delete
 * only when unused).
 */
class SubZoneResource extends ZoneResource
{
    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Sub-zones';

    protected static ?string $modelLabel = 'sub-zone';

    protected static ?string $slug = 'sub-zones';

    public static function isSubZoneScreen(): bool
    {
        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubZones::route('/'),
            'create' => Pages\CreateSubZone::route('/create'),
            'edit' => Pages\EditSubZone::route('/{record}/edit'),
        ];
    }
}
