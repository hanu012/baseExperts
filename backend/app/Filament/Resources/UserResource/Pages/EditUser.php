<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Exceptions\KycIncompleteException;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorVerificationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->deleteAction(),
            $this->restoreAction(),
        ];
    }

    /**
     * Deletes through User::deleteWithTombstone() rather than Filament's
     * DeleteAction.
     *
     * A plain soft delete leaves the row holding its email on a unique index
     * that does not exempt trashed rows, so the person could never sign up
     * again (SPEC section 4.10 requires deletion for app store compliance).
     * The tombstone rewrites the address, keeps the original for restore, and
     * revokes every Sanctum token — all in one transaction.
     */
    private function deleteAction(): Action
    {
        return Action::make('deleteWithTombstone')
            ->label('Delete account')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (User $record): bool => ! $record->trashed())
            // Reaching this page needs users.update, not users.delete — and
            // UserPolicy::delete also refuses deleting yourself.
            ->authorize(fn (User $record): bool => Auth::user()?->can('delete', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Delete this account?')
            ->modalDescription(
                'The account is soft-deleted and its email address is released so the person '
                .'can sign up again. Their leads, reviews and history are preserved. '
                .'All signed-in devices are logged out immediately. This can be undone.'
            )
            ->modalSubmitActionLabel('Delete account')
            ->action(function (User $record) {
                $record->deleteWithTombstone();

                Notification::make()
                    ->title('Account deleted')
                    ->body('The email address has been released and all devices signed out.')
                    ->success()
                    ->send();

                $this->redirect($this->getResource()::getUrl('index'));
            });
    }

    /**
     * Restores the account and puts the original email back.
     *
     * The address may have been claimed by someone else while this account
     * was deleted — releasing it is the whole point of the tombstone — so the
     * restore refuses rather than violating the unique index or silently
     * handing back an account nobody can sign in as.
     */
    private function restoreAction(): Action
    {
        return Action::make('restoreWithOriginalEmail')
            ->label('Restore account')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (User $record): bool => $record->trashed())
            ->authorize(fn (User $record): bool => Auth::user()?->can('restore', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Restore this account?')
            ->modalDescription(fn (User $record): string => $record->original_email
                ? 'The account is restored and its email set back to '.$record->original_email.'. '
                    .'They will need to reset their password, since all devices were signed out on deletion.'
                : 'The account is restored. No original email was recorded, so it keeps its current address.')
            ->modalSubmitActionLabel('Restore account')
            ->action(function (User $record) {
                try {
                    $record->restoreWithOriginalEmail();
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title('Could not restore this account')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Account restored')
                    ->body('Email restored to '.$record->fresh()->email.'.')
                    ->success()
                    ->send();

                $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
            });
    }

    /**
     * The password field is absent from the form on purpose: an admin cannot
     * read or set an existing password. A forgotten one goes through the
     * email reset flow built in task 0.3.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['password']);

        $this->vendorDecision = $data['vendor_decision'] ?? null;
        $this->vendorDecisionReason = $data['vendor_decision_reason'] ?? null;
        unset($data['vendor_decision'], $data['vendor_decision_reason']);

        return $data;
    }

    private ?string $vendorDecision = null;

    private ?string $vendorDecisionReason = null;

    /**
     * Applies the Approve/Reject staged in the Verification section. Runs
     * after the vendor fieldset has saved, so the service sees — and then
     * overrides — the status that was just written from the dropdown.
     * Permission is re-checked here: the staged value arrives in the
     * request, so the hidden field alone is not the boundary.
     */
    protected function afterSave(): void
    {
        $decision = $this->vendorDecision;
        $reason = trim((string) $this->vendorDecisionReason);
        $this->vendorDecision = $this->vendorDecisionReason = null;

        $vendor = $this->record->vendor()->first();

        if ($decision === null || $vendor === null || ! (Auth::user()?->can('verify', Vendor::class) ?? false)) {
            return;
        }

        $service = app(VendorVerificationService::class);

        if ($decision === 'approve') {
            try {
                $service->approve($vendor, Auth::user());
            } catch (KycIncompleteException $e) {
                Notification::make()->title('Cannot approve yet')->body($e->getMessage())->danger()->persistent()->send();
                $this->data['vendor_decision'] = null;

                return;
            }

            Notification::make()->title('Vendor approved')->success()->send();
        } elseif ($decision === 'reject' && $reason !== '') {
            $service->reject($vendor, Auth::user(), $reason);

            Notification::make()->title('Vendor rejected')->danger()->send();
        }

        $this->data['vendor_decision'] = null;
        $this->data['vendor_decision_reason'] = null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Puts the user form itself in the tab strip alongside Vendor and
     * Media, rather than above them.
     *
     * Without this Filament stacks the form and then the relation tabs
     * underneath, so the vendor record and its uploads sit below a long
     * form and are easy to miss entirely. Combined, the page reads as one
     * record with three views of it.
     */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    /**
     * The label for that first tab. "Account" rather than Filament's
     * default record title, which would repeat the name already in the
     * page heading.
     */
    public function getContentTabLabel(): ?string
    {
        return 'Account';
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-user';
    }
}
