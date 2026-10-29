<?php

namespace App\Filament\Resources\VendorVerificationResource\Pages;

use App\Filament\Resources\VendorVerificationResource;
use App\Models\Vendor;
use App\Services\VendorVerificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewVendorVerification extends ViewRecord
{
    protected static string $resource = VendorVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
        ];
    }

    /**
     * Same transition as the table's inline approve action
     * (VendorVerificationResource::approveAction()) — kept separate because
     * page header actions and table row actions are different Filament
     * classes, but both call VendorVerificationService so the actual state
     * change lives in one place.
     */
    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => Auth::user()?->can('verify', Vendor::class) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Approve this vendor?')
            ->modalDescription('The vendor becomes active and visible to customers immediately.')
            ->action(function (Vendor $record) {
                app(VendorVerificationService::class)->approve($record, Auth::user());

                Notification::make()
                    ->title('Vendor approved')
                    ->success()
                    ->send();

                $this->redirect($this->getResource()::getUrl('index'));
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('verify', Vendor::class) ?? false)
            ->modalHeading('Reject this vendor?')
            ->form([
                Textarea::make('reason')
                    ->label('Rejection reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (Vendor $record, array $data) {
                app(VendorVerificationService::class)->reject($record, Auth::user(), $data['reason']);

                Notification::make()
                    ->title('Vendor rejected')
                    ->danger()
                    ->send();

                $this->redirect($this->getResource()::getUrl('index'));
            });
    }
}
