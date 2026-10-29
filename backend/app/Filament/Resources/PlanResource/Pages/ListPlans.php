<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Filament\Support\RecordStatus;
use App\Models\Plan;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** All / Active / Inactive, each with its count. */
    public function getTabs(): array
    {
        return RecordStatus::tabs(Plan::class, 'plans');
    }

    public function getSubheading(): ?HtmlString
    {
        return new HtmlString(
            'Plans are <strong>deactivated, not deleted</strong> — every subscription '
            .'records the plan it was sold on, so removing one would break the history '
            .'behind renewals, commissions and revenue reporting. Use <em>Change status</em> '
            .'to stop offering a plan; subscriptions already on it keep running to '
            .'their expiry. <strong>Editing a price never changes what past customers '
            .'were charged</strong> — price and duration are copied onto each '
            .'subscription at purchase.'
        );
    }
}
