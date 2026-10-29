<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\UserRole;
use App\Filament\Resources\VendorResource;
use App\Models\User;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The "More info" tab — the vendor's full record, read-only, inline.
 *
 * The Account tab used to end with a link out to the vendor page for the
 * KYC documents and the plan/quota breakdown. A link is a dead end in a
 * tabbed screen: you leave the user you were working on and have to come
 * back. The same content is now a tab beside Account and Media.
 *
 * The schema is [VendorResource]'s own infolist, reused rather than
 * rebuilt — it already renders the KYC images with their full-size
 * previews, the live plan and quota, and the selected services and zones,
 * and a second copy would be the thing that falls behind.
 *
 * Renders an infolist rather than the table a relation manager normally
 * shows, via its own view. [RelationManager] already implements
 * HasInfolists for its view-record modal, so overriding infolist() and
 * the view is all this takes. table() still has to exist because the
 * class is contractually a HasTable; nothing renders it.
 */
class VendorDetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendor';

    protected static ?string $title = 'More info';

    protected static ?string $icon = 'heroicon-o-identification';

    protected static string $view = 'filament.relation-managers.vendor-details';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User
            && $ownerRecord->role === UserRole::Vendor
            && $ownerRecord->vendor !== null;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return VendorResource::infolist(
            $infolist->record($this->getOwnerRecord()->vendor),
        );
    }

    /**
     * Required by the HasTable contract this class inherits. The custom
     * view never renders it.
     */
    public function table(Table $table): Table
    {
        return $table->columns([]);
    }
}
