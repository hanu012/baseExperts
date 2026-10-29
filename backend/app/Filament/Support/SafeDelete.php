<?php

namespace App\Filament\Support;

use Filament\Actions\MountableAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Delete for master data (categories, subcategories, zones) — offered, but
 * only while nothing depends on the record.
 *
 * The model answers through deletionBlocker(): null when it is safe, or a
 * plain-words reason when it is not (still subscribed, has children, has
 * leads). The button is then disabled with that reason as its tooltip, and
 * checked again just before deleting in case something changed while the
 * confirmation was open. PreventsDeletionWhileSubscribed and the foreign
 * keys remain the last line for callers outside the panel.
 *
 * Works for a table row action and a page header action alike.
 */
class SafeDelete
{
    public static function configure(MountableAction $action): MountableAction
    {
        return $action
            ->disabled(fn (Model $record): bool => $record->deletionBlocker() !== null)
            ->tooltip(fn (Model $record): ?string => $record->deletionBlocker())
            ->modalDescription('This permanently deletes it. It cannot be undone.')
            ->before(function (MountableAction $action, Model $record): void {
                $reason = $record->deletionBlocker();

                if ($reason === null) {
                    return;
                }

                Notification::make()
                    ->title('Cannot delete')
                    ->body($reason)
                    ->danger()
                    ->send();

                $action->cancel();
            });
    }
}
