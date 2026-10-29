<?php

namespace App\Filament\SuperAdmin\Resources\AdminResource\Pages;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Filament\SuperAdmin\Resources\AdminResource;
use App\Models\User;
use App\Services\AccountInviteService;
use App\Services\AdminAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new admin account — the same hand-over as any account an admin
 * creates (UserResource's CreateUser): approved at once, must change its
 * password, and either emailed a "set your password" link (Firebase
 * sign-in on) or given a temporary password shown once.
 */
class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    private string $temporaryPassword = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->temporaryPassword = User::generateTemporaryPassword();

        $data['role'] = UserRole::Admin->value;
        $data['password'] = $this->temporaryPassword;
        $data['permissions'] = app(AdminAccountService::class)->permissionsFor(
            (bool) ($data['is_super_admin'] ?? false),
            $data['permissions'] ?? [],
        );
        unset($data['is_super_admin']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        $user->recordApprovalDecision(ApprovalStatus::Approved, auth()->user(), 'Created by a super-admin.');
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $sentTo = app(AccountInviteService::class)->send($user);

        if ($sentTo !== null) {
            Notification::make()
                ->title('Password link sent')
                ->body("{$user->name} was emailed a link to set their own password ({$sentTo}).")
                ->success()
                ->persistent()
                ->send();

            return;
        }

        UserResource::sendTemporaryPasswordNotification(
            $user,
            $this->temporaryPassword,
            'Share it with them now — this is the only time it is shown.',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
