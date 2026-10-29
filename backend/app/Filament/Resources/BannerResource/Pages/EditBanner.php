<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use App\Models\Banner;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBanner extends EditRecord
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        // Unlike CategoryResource/ZoneResource, a DeleteAction here is
        // deliberate and correct — see Banner's own docblock for why
        // SPEC section 10's no-hard-delete rule doesn't apply to this
        // content type.
        //
        // Filament's DeleteAction does NOT auto-gate against the
        // model policy just by being declared — every other action
        // in this codebase (VendorVerificationResource, ReviewResource,
        // CommissionPayouts) explicitly checks the policy in
        // ->visible() rather than relying on implicit authorization,
        // and this needs the same explicit check or `banners.delete`
        // grants nothing.
        return [
            Actions\DeleteAction::make()
                ->visible(fn (Banner $record): bool => Auth::user()?->can('delete', $record) ?? false),
        ];
    }
}
