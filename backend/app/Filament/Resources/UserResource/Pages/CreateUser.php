<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\ApprovalStatus;
use App\Exceptions\KycIncompleteException;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AccountInviteService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The generated password, held only for the notification below. Never
     * stored anywhere in plaintext.
     */
    private string $temporaryPassword = '';

    /**
     * Generates the temp password and approves the account.
     *
     * SPEC section 5.2: creating a salesman generates a temp password shown
     * once. Every role gets one here, because an admin-created account has no
     * password otherwise and there is no self-service signup for it.
     *
     * The account is approved on creation because an admin vouching for it
     * IS the approval — sending it to the pending queue would mean the admin
     * who just typed it in has to go and approve their own work.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->temporaryPassword = self::generatePassword();

        // The 'hashed' cast on User::$casts turns this into a bcrypt hash on
        // save; the plaintext never reaches the database.
        $data['password'] = $this->temporaryPassword;

        // approval_status is deliberately NOT set here — it is absent from
        // User::$fillable, so mass assignment silently drops it. It is
        // recorded in afterCreate() through recordApprovalDecision()
        // instead, rather than widening fillable for a field no user input
        // should ever set.

        return $data;
    }

    /**
     * Shown once, and only once — there is no way back to this value
     * afterwards, by design. A persistent notification so it survives until
     * the admin dismisses it rather than fading while they reach for a phone.
     */
    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        // The admin filling in this form is the approval (SPEC section 3.1).
        // Recorded against them by name so the audit trail shows who let the
        // account in, the same as it would for a decision taken from the
        // pending queue.
        //
        // Except a vendor whose compulsory KYC documents are not verified
        // yet (VendorKycService): it stays Pending, and the admin is taken
        // to its page to upload them on the Documents tab.
        try {
            $user->recordApprovalDecision(
                ApprovalStatus::Approved,
                auth()->user(),
                'Created directly by an administrator.',
            );
        } catch (KycIncompleteException $e) {
            $this->createdPendingKyc = true;

            Notification::make()
                ->title('Created as Pending')
                ->body($e->getMessage().' Upload them on the Documents tab, verify them, then approve.')
                ->warning()
                ->persistent()
                ->send();
        }

        // Every account created here is handed a temporary password the admin
        // read out or messaged over, so every one of them must set their own
        // before doing anything else (SPEC section 2.1 states this for
        // salesmen; the same reasoning covers the other three roles, which
        // also receive a temp password from this form).
        //
        // Set here rather than as a column default: the default is false, so
        // that ordinary self-registered users — who choose their own password
        // — are not asked to change it immediately.
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        // Firebase sign-in on: they are emailed a "set your password" link
        // and the temporary password is never shown, so nobody holds it.
        // If the email cannot go, the temporary password is shown as before.
        $sentTo = app(AccountInviteService::class)->send($user);

        if ($sentTo !== null) {
            Notification::make()
                ->title('Password link sent')
                ->body("{$user->name} was emailed a link to set their own password ({$sentTo}). "
                    .'The link expires after about an hour — use "Send password link" on the Users list to send a new one.')
                ->success()
                ->persistent()
                ->send();

            return;
        }

        UserResource::sendTemporaryPasswordNotification(
            $user,
            $this->temporaryPassword,
            'Share it with them now — this is the only time it is shown. '
            .'It cannot be recovered afterwards; a forgotten password is replaced by issuing a new temporary one from the Users list.',
        );
    }

    /**
     * Delegates to User::generateTemporaryPassword() — SubscriptionService
     * needs the identical alphabet/length for the vendor temp password
     * shared at Subscribe (SPEC section 2.2), so the logic lives on the
     * model rather than being duplicated here.
     */
    public static function generatePassword(int $length = 20): string
    {
        return User::generateTemporaryPassword($length);
    }

    /** Set when a vendor was left Pending for its KYC documents. */
    private bool $createdPendingKyc = false;

    protected function getRedirectUrl(): string
    {
        // Straight to the vendor's page, where the Documents tab is.
        return $this->createdPendingKyc
            ? $this->getResource()::getUrl('edit', ['record' => $this->getRecord()])
            : $this->getResource()::getUrl('index');
    }
}
