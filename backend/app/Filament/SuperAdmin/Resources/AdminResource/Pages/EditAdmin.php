<?php

namespace App\Filament\SuperAdmin\Resources\AdminResource\Pages;

use App\Filament\SuperAdmin\Resources\AdminResource;
use App\Models\User;
use App\Services\AdminAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

/**
 * Editing an admin. Your own row cannot be opened here (the list hides Edit
 * for it, and mount() refuses) — changing your own access is exactly the
 * escalation path the old Users screen guarded against.
 */
class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        abort_if($this->getRecord()->is(auth()->user()), 403, 'You cannot edit your own admin account here.');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['is_super_admin'] = $this->getRecord()->isSuperAdmin();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $accounts = app(AdminAccountService::class);

        if (array_key_exists('is_super_admin', $data)) {
            $makeSuper = (bool) $data['is_super_admin'];

            if (! $makeSuper && $accounts->isLastSuperAdmin($record)) {
                Notification::make()
                    ->title('This is the last super-admin')
                    ->body('Make another admin a super-admin before taking full access away from this one.')
                    ->danger()
                    ->send();

                throw new Halt;
            }

            $data['permissions'] = $accounts->permissionsFor($makeSuper, $data['permissions'] ?? []);
        }

        unset($data['is_super_admin']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
