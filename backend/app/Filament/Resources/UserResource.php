<?php

namespace App\Filament\Resources;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Exceptions\KycIncompleteException;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\AccountInviteService;
use App\Services\VendorKycService;
use App\Models\Vendor;
use App\Models\Zone;
use Filament\Forms\Components\Actions as FormActions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * User management for salesmen, vendors and customers (SPEC section 5.2).
 * Admin accounts moved to Super Admin → Admins (AdminResource); they are
 * left out of this screen's query entirely.
 *
 * THIS IS THE ADMIN-CREATES-DIRECTLY PATH, and is deliberately not bound by
 * the self-registration restriction. SPEC section 1 forbids salesmen from
 * signing themselves up, which is enforced on the API by RegisterRequest
 * limiting `role` to vendor|customer. That rule governs public registration.
 * An admin working in this panel may create any of the four roles — which is
 * exactly what section 5.2 asks for. Both rules are pinned by tests so
 * neither drifts into the other.
 *
 * Accounts created here are approved at creation: the admin filling in the
 * form is the vetting, so sending the account to the pending queue would
 * only ask them to approve their own work.
 *
 * This list is also where self-registered accounts are let in. Admin
 * approval is the single gate on an account (SPEC section 3.1) — there is
 * no email verification and the platform sends no mail at all, so the
 * Approve action here is the only thing that makes a signup usable.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Deliberately NOT scoped withoutTrashed(): the unique
                        // index on users.email covers trashed rows too, so
                        // exempting them here would let validation pass and
                        // then fail at the database. A genuinely released
                        // address does not collide anyway — deletion
                        // tombstones the row, so the original is no longer
                        // held by anything. Same reasoning as RegisterRequest.
                        ->unique(ignoreRecord: true)
                        ->helperText('Becomes the login. The platform sends no email — a forgotten password is reset from this list.'),

                    // No "admin": admin accounts are managed in Super Admin →
                    // Admins. The Select's own in() rule refuses a crafted
                    // "admin" too.
                    Select::make('role')
                        ->options(static::roleOptions())
                        ->required()
                        ->live()
                        // Fixed after creation: changing it would strand the
                        // profile row and anything hanging off it, and there
                        // is no sane migration from vendor to customer.
                        ->disabled(fn (string $operation): bool => $operation !== 'create')
                        // Dehydrated ONLY on create. A bare ->dehydrated()
                        // overrides Filament's default of excluding disabled
                        // fields from the save, which made `disabled` purely
                        // cosmetic on edit: a crafted Livewire submission
                        // could still set role, and a customer could be
                        // promoted to admin — bypassing UserPolicy::create(),
                        // which restricts making admins to super-admins.
                        // Verified exploitable before this fix.
                        //
                        // Same round-trip hole as the permissions field, which
                        // is why that one uses visible() rather than
                        // disabled(). See CLAUDE.md's Filament conventions.
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'Cannot be changed later — the profile below depends on it.'
                            : 'Role cannot be changed after creation.'),

                    // No permissions field: admins and their permissions are
                    // managed in Super Admin → Admins (AdminResource), so the
                    // field — and its escalation risk — is gone from here.
                ]),

            // Each profile fieldset is bound with a condition, so switching
            // role on create writes only the matching row. Filament deletes
            // the related record when the condition is false, which stops an
            // orphan profile surviving a role change.
            Fieldset::make('Salesman profile')
                ->relationship('salesman', condition: fn (Get $get): bool => $get('role') === UserRole::Salesman->value)
                ->visible(fn (Get $get): bool => $get('role') === UserRole::Salesman->value)
                ->columns(2)
                ->schema([
                    TextInput::make('employee_code')
                        ->required()
                        ->maxLength(255)
                        ->unique(table: 'salesmen', ignoreRecord: true),

                    // Stored as the zone's display name, not an id — the
                    // column is free text the salesman app shows as-is.
                    Select::make('region')
                        ->label('Assigned region')
                        ->searchable()
                        // The saved value, never the submitted state — taking
                        // the state would let any typed region validate.
                        ->options(fn (?Model $record): array => static::regionOptions($record?->getAttribute('region')))
                        ->in(fn (?Model $record): array => array_keys(static::regionOptions($record?->getAttribute('region'))))
                        ->helperText('Shown on the salesman\'s profile screen.'),

                    TextInput::make('phone')
                        ->tel()
                        ->required()
                        ->maxLength(20)
                        ->unique(table: 'salesmen', ignoreRecord: true),

                    // Monthly target and commission rate are not edited here;
                    // both columns default to 0.

                    Toggle::make('is_active')->default(true),
                ]),

            Fieldset::make('Vendor profile')
                ->relationship('vendor', condition: fn (Get $get): bool => $get('role') === UserRole::Vendor->value)
                ->visible(fn (Get $get): bool => $get('role') === UserRole::Vendor->value)
                ->columns(2)
                ->schema([
                    TextInput::make('business_name')->required()->maxLength(255),
                    TextInput::make('owner_name')->required()->maxLength(255),

                    TextInput::make('phone')
                        ->tel()
                        ->required()
                        ->maxLength(20)
                        // SPEC section 2.2 rejects a duplicate vendor phone
                        // before anything else is saved. It is also the number
                        // behind the customer Call button.
                        ->unique(table: 'vendors', ignoreRecord: true),

                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'pending_payment' => 'Pending payment',
                            'active' => 'Active',
                            'grace' => 'Grace',
                            'expired' => 'Expired',
                            'pending_verification' => 'Pending verification',
                            'rejected' => 'Rejected',
                        ])
                        // Draft: an admin-created vendor has no subscription
                        // yet, the same position the salesman flow is in
                        // before payment. pending_verification and rejected
                        // are listed only so the field can display a vendor
                        // already in those states — move them with the
                        // Verification section's Approve/Reject, not here.
                        ->default('draft')
                        ->required(),

                    TextInput::make('address')->maxLength(255)->columnSpanFull(),

                    TextInput::make('latitude')->numeric()->step('0.0000001'),
                    TextInput::make('longitude')->numeric()->step('0.0000001'),

                    Toggle::make('is_suspended')
                        ->helperText('Policy violations. Independent of subscription dates.'),
                ]),

            static::vendorVerificationSection(),

            Fieldset::make('Customer profile')
                ->relationship('customer', condition: fn (Get $get): bool => $get('role') === UserRole::Customer->value)
                ->visible(fn (Get $get): bool => $get('role') === UserRole::Customer->value)
                ->columns(2)
                ->schema([
                    TextInput::make('phone')->tel()->maxLength(20),
                    TextInput::make('pincode')->maxLength(10),
                    TextInput::make('latitude')->numeric()->step('0.0000001'),
                    TextInput::make('longitude')->numeric()->step('0.0000001'),
                ]),
        ]);
    }

    /**
     * Verification, on the Account tab beside the vendor fields it judges.
     *
     * This used to be a sidebar entry of its own ("Vendor Verification"),
     * then briefly a tab; both meant deciding on a vendor while their
     * details were on a different screen. The decision and the thing
     * being decided now sit together.
     *
     * Only rendered for a saved vendor user — on the create form there is
     * no vendor row to approve yet, and the role select can still change.
     *
     * Approve/Reject only stage a decision; EditUser::afterSave() applies
     * it on Save changes through VendorVerificationService, the same path
     * the API and the (still-registered) VendorVerificationResource use:
     * it moves the vendor's status AND the owning user's approval in one
     * transaction, so a second hand-rolled status write here would be the
     * thing that lets the two drift apart.
     */
    private static function vendorVerificationSection(): Section
    {
        return Section::make('Verification')
            // Keyed so the header actions are addressable — a Section has
            // no state path of its own for tests to reach them by.
            ->key('vendorVerification')
            ->description('Approve or reject this vendor. Approving also lets the account into the app.')
            // Keyed off the saved record alone, not the role select: only
            // a vendor has a vendor row, so the row's existence already
            // says everything the role check would, and it does not
            // depend on whether the select's live state is the enum or
            // its string value.
            ->visible(fn (?User $record): bool => $record?->vendor !== null)
            ->columns(2)
            ->schema([
                // The decision is staged here and only applied by EditUser
                // when the admin clicks Save changes; Cancel discards it.
                // Hidden rather than disabled so it is not even part of the
                // form state for someone who may not verify.
                Hidden::make('vendor_decision')
                    ->rules(['nullable', 'in:approve,reject'])
                    ->visible(fn (): bool => Auth::user()?->can('verify', Vendor::class) ?? false),

                Hidden::make('vendor_decision_reason')
                    ->rules(['nullable', 'string', 'max:1000'])
                    ->visible(fn (): bool => Auth::user()?->can('verify', Vendor::class) ?? false),

                Placeholder::make('vendor_status')
                    ->label('Current status')
                    ->content(fn (Get $get, ?User $record): string => match ($get('vendor_decision')) {
                        'approve' => 'Will be approved when you click Save changes',
                        'reject' => 'Will be rejected when you click Save changes',
                        default => match ($record?->vendor?->status) {
                            'active' => 'Active — visible to customers',
                            'rejected' => 'Rejected',
                            'pending_verification' => 'Awaiting verification',
                            'pending_payment' => 'Awaiting payment',
                            'draft' => 'Draft — not submitted',
                            default => '—',
                        },
                    }),

                // The approval rule (VendorKycService): what is still
                // missing, managed on the Documents tab beside this one.
                Placeholder::make('vendor_kyc')
                    ->label('KYC documents')
                    ->content(fn (?User $record): string => $record === null
                        ? '—'
                        : (static::missingKyc($record) === []
                            ? 'All compulsory documents verified'
                            : 'Waiting for: '.implode(', ', static::missingKyc($record)).' (see the Documents tab)')),

                Placeholder::make('vendor_rejection_reason')
                    ->label('Rejection reason')
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?User $record): bool => static::effectiveVendorStatus($get, $record) === 'rejected')
                    ->content(fn (Get $get, ?User $record): string => $get('vendor_decision') === 'reject'
                        ? ($get('vendor_decision_reason') ?: '—')
                        : ($record?->vendor?->rejection_reason ?? '—')),

                // No link out to the vendor page here: the KYC documents
                // and the plan/quota breakdown are the "More info" tab
                // beside this one, so the admin never leaves the user.

                // An Actions component rather than the Section's own
                // headerActions: header actions are not reachable through
                // Component::getAction(), so nothing could test them.
                FormActions::make([
                    FormAction::make('approveVendor')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this vendor?')
                    ->modalDescription('Nothing changes until you click Save changes. Then the vendor becomes visible to customers and the account is let into the app.')
                    ->visible(fn (Get $get, ?User $record): bool => $record?->vendor !== null
                        && static::effectiveVendorStatus($get, $record) !== 'active'
                        && (Auth::user()?->can('verify', Vendor::class) ?? false))
                    ->disabled(fn (?User $record): bool => $record !== null && static::missingKyc($record) !== [])
                    ->tooltip(fn (?User $record): ?string => $record !== null && static::missingKyc($record) !== []
                        ? KycIncompleteException::describe(static::missingKyc($record))
                        : null)
                    ->action(function (Set $set) {
                        $set('vendor_decision', 'approve');
                        $set('vendor_decision_reason', null);

                        Notification::make()
                            ->title('Approval staged')
                            ->body('Click Save changes to apply it.')
                            ->warning()
                            ->send();
                    }),

                FormAction::make('rejectVendor')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject this vendor?')
                    ->form([
                        Textarea::make('reason')
                            ->label('Rejection reason')
                            ->helperText('Shown to the vendor in the app.')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->visible(fn (Get $get, ?User $record): bool => $record?->vendor !== null
                        && static::effectiveVendorStatus($get, $record) !== 'rejected'
                        && (Auth::user()?->can('verify', Vendor::class) ?? false))
                    ->action(function (Set $set, array $data) {
                        $set('vendor_decision', 'reject');
                        $set('vendor_decision_reason', $data['reason']);

                        Notification::make()
                            ->title('Rejection staged')
                            ->body('Click Save changes to apply it.')
                            ->warning()
                            ->send();
                    }),
                ])->key('vendorDecision')->columnSpanFull(),
            ]);
    }

    /**
     * The one-time temporary password, with ways to hand it over: copy the
     * password, or share a ready-to-send message with the login details.
     *
     * Copy and Share dispatch browser events handled by the script in
     * resources/views/filament/hooks/credential-share.blade.php. Inline
     * x-on:click code does not survive here: a sent notification is stored
     * and rebuilt, which drops alpineClickHandler() and double-escapes any
     * quotes in extraAttributes — the old copy button ran broken JS.
     */
    public static function sendTemporaryPasswordNotification(User $user, string $password, string $note): void
    {
        $where = match ($user->role) {
            UserRole::Salesman => 'the Salesman app',
            UserRole::Vendor => 'the Vendor app',
            UserRole::Customer => 'the Customer app',
            UserRole::Admin => 'the admin panel',
        };

        $message = "Hi {$user->name}, your Service Marketplace account is ready.\n\n"
            ."Sign in to {$where} with:\n"
            ."Email: {$user->email}\n"
            ."Temporary password: {$password}\n\n"
            .'You will be asked to set your own password the first time you sign in.';

        Notification::make()
            ->title('Temporary password for '.$user->name)
            ->body($password."\n\n".$note)
            ->persistent()
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('copy')
                    ->label('Copy password')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->dispatch('copy-temporary-credential', ['text' => $password, 'confirmation' => 'Password copied']),

                \Filament\Notifications\Actions\Action::make('whatsapp')
                    ->label('Share on WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url('https://wa.me/?text='.rawurlencode($message), shouldOpenInNewTab: true),

                // The browser's own share sheet (Telegram, Messages, email
                // apps...). Where the browser has none, it copies the whole
                // message instead so it can be pasted anywhere.
                \Filament\Notifications\Actions\Action::make('share')
                    ->label('Share…')
                    ->icon('heroicon-o-share')
                    ->color('gray')
                    ->dispatch('share-temporary-credential', ['text' => $message]),
            ])
            ->send();
    }

    /**
     * Active zones as "Area · City" (or just the city for a top-level zone),
     * keyed by that same label. A region saved before this was a dropdown
     * is kept as an option so editing the salesman does not blank it.
     *
     * @return array<string, string>
     */
    private static function regionOptions(?string $current): array
    {
        $options = Zone::query()
            ->where('is_active', true)
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Zone $zone): array {
                $label = $zone->parent ? $zone->name.' · '.$zone->parent->name : $zone->name;

                return [$label => $label];
            })
            ->all();

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options = [$current => $current] + $options;
        }

        return $options;
    }

    /**
     * The vendor's status as the page should present it: a staged but
     * unsaved decision counts, so the buttons offer the opposite choice.
     */
    private static function effectiveVendorStatus(Get $get, ?User $record): ?string
    {
        return match ($get('vendor_decision')) {
            'approve' => 'active',
            'reject' => 'rejected',
            default => $record?->vendor?->status,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // A row click opens the read-only view, not the edit form.
            ->recordUrl(null)
            ->recordAction('view')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): string => '#'.$record->getKey()
                        .(static::profilePhone($record) ? ' · '.static::profilePhone($record) : '')),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    // A tombstoned address is bookkeeping, not something to
                    // read as a contact.
                    ->formatStateUsing(fn (User $record): string => $record->isTombstoned()
                        ? '(deleted account)'
                        : $record->email)
                    ->description(fn (User $record): ?string => $record->isTombstoned()
                        ? 'was '.$record->original_email
                        : null),

                TextColumn::make('role')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (UserRole $state): string => ucfirst($state->value))
                    ->color(fn (UserRole $state): string => match ($state) {
                        UserRole::Admin => 'danger',
                        UserRole::Salesman => 'warning',
                        UserRole::Vendor => 'info',
                        UserRole::Customer => 'gray',
                    }),

                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                    ->color(fn (ApprovalStatus $state): string => $state->color())
                    // The rejection reason is the first thing anyone asks
                    // about, so it is on the row rather than a click away.
                    ->description(fn (User $record): ?string => $record->isRejected()
                        ? $record->approval_note
                        : null),

                TextColumn::make('created_at')
                    ->label('Added on')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Last modified')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->label('Deleted')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(static::roleOptions()),

                // Defaulted to Pending is tempting but wrong — an admin
                // opening Users to find a specific person would see an
                // empty table and think the account was gone. The pending
                // queue is surfaced by the navigation badge instead.
                SelectFilter::make('approval_status')
                    ->label('Approval')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(
                        fn (ApprovalStatus $status) => [$status->value => $status->label()]
                    )),

                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading(fn (User $record): string => $record->name)
                    ->modalDescription(fn (User $record): string => ucfirst($record->role->value))
                    ->extraModalFooterActions(fn (ViewAction $action): array => [
                        $action->makeModalAction('edit')
                            ->label('Edit')
                            ->icon('heroicon-o-pencil-square')
                            ->color('primary')
                            ->url(static::getUrl('edit', ['record' => $action->getRecord()])),
                    ]),

                \Filament\Tables\Actions\EditAction::make(),

                // The approval gate (SPEC section 3.1). This is the only way
                // a self-registered account becomes usable — there is no
                // emailed verification link any more.
                \Filament\Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this account?')
                    ->modalDescription(
                        'They will be able to use the app immediately, on every '
                        .'device they are already signed in on.'
                    )
                    ->visible(fn (User $record): bool => ! $record->isApproved())
                    ->authorize(fn (User $record): bool => Auth::user()?->can('update', $record) ?? false)
                    // A vendor needs every compulsory KYC document Verified
                    // first (VendorKycService) — greyed out with the reason.
                    ->disabled(fn (User $record): bool => static::missingKyc($record) !== [])
                    ->tooltip(fn (User $record): ?string => static::missingKyc($record) !== []
                        ? KycIncompleteException::describe(static::missingKyc($record))
                        : null)
                    ->action(function (User $record): void {
                        try {
                            $record->recordApprovalDecision(
                                ApprovalStatus::Approved,
                                auth()->user(),
                            );
                        } catch (KycIncompleteException $e) {
                            \Filament\Notifications\Notification::make()->title('Cannot approve yet')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                // Rejection takes a reason because the app shows it to the
                // user — a rejection with no explanation generates a support
                // call that the admin then has to answer anyway.
                \Filament\Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (User $record): bool => ! $record->isRejected())
                    ->authorize(fn (User $record): bool => Auth::user()?->can('update', $record) ?? false)
                    ->form([
                        Textarea::make('approval_note')
                            ->label('Reason')
                            ->helperText('Shown to the user in the app.')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->recordApprovalDecision(
                            ApprovalStatus::Rejected,
                            auth()->user(),
                            $data['approval_note'],
                        );
                    }),

                // Re-sends the "set your password" email to an account that
                // has never chosen its own password (an expired or missed
                // invite). Not a reset: it is hidden once the owner has set
                // one, and the admin never sees or sets the password.
                \Filament\Tables\Actions\Action::make('sendPasswordLink')
                    ->label('Send password link')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Email a password link?')
                    ->modalDescription(fn (User $record): string => "{$record->email} gets a link to set their own password. It expires after about an hour.")
                    ->visible(fn (User $record): bool => app(AccountInviteService::class)->canInvite($record))
                    ->authorize(fn (User $record): bool => Auth::user()?->can('update', $record) ?? false)
                    ->action(function (User $record): void {
                        $sentTo = app(AccountInviteService::class)->send($record);

                        $sentTo !== null
                            ? \Filament\Notifications\Notification::make()->title("Password link sent to {$sentTo}")->success()->send()
                            : \Filament\Notifications\Notification::make()->title('The email could not be sent')->body('Ask a super-admin to check Super Admin → System settings → Sign-in, then try again.')->danger()->send();
                    }),

                // No "Reset password" here, deliberately: admins never set
                // or see a user's password. A forgotten password is reset
                // by the user from the app's "Forgot password?" (an emailed
                // link — see PasswordResetService).
            ])
            ->bulkActions([
                // Bulk approve only. There is no bulk reject on purpose:
                // rejecting requires a reason that is shown to the user, and
                // one reason pasted across a mixed selection is worse than
                // no reason at all.
                \Filament\Tables\Actions\BulkAction::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve selected accounts?')
                    ->modalDescription(
                        'Each one will be able to use the app immediately. Accounts '
                        .'that are already approved are skipped.'
                    )
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $admin = auth()->user();
                        $waitingForKyc = [];

                        foreach ($records as $record) {
                            // Same per-record policy check as the row action.
                            if ($record->isApproved() || ! $admin?->can('update', $record)) {
                                continue;
                            }

                            try {
                                $record->recordApprovalDecision(ApprovalStatus::Approved, $admin);
                            } catch (KycIncompleteException) {
                                $waitingForKyc[] = $record->name;
                            }
                        }

                        if ($waitingForKyc !== []) {
                            \Filament\Notifications\Notification::make()
                                ->title('Some vendors were not approved')
                                ->body('Their compulsory documents are not all verified yet: '.implode(', ', $waitingForKyc).'.')
                                ->warning()
                                ->persistent()
                                ->send();
                        }
                    }),

                // Bulk delete, deliberately harder to trigger than the
                // per-row one on the Edit page: a checkbox selection is easy
                // to get wrong, and deleting a user releases their email for
                // reuse and force-signs them out everywhere. The type-to-
                // confirm field is on top of Filament's own confirmation
                // modal, not instead of it. UserPolicy::delete() is still
                // re-checked per record here — a custom BulkAction does not
                // auto-authorize the way DeleteBulkAction does — so admin
                // accounts (unless you're a super-admin) and your own
                // account are silently skipped rather than deleted.
                \Filament\Tables\Actions\BulkAction::make('deleteSelected')
                    ->label('Delete selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected users?')
                    ->modalDescription(
                        'This releases each account\'s email/phone for reuse, force-signs '
                        .'them out on every device, and cannot be undone as a batch. Admin '
                        .'accounts and your own account are always skipped.'
                    )
                    ->modalSubmitActionLabel('Delete')
                    ->form([
                        TextInput::make('confirmation')
                            ->label('Type DELETE to confirm')
                            ->required()
                            ->rule('in:DELETE')
                            ->validationMessages(['in' => 'Type DELETE exactly, in capitals, to confirm.']),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $deleted = 0;
                        $skipped = 0;

                        foreach ($records as $record) {
                            if (Auth::user()?->can('delete', $record)) {
                                // Not delete(): that keeps the email held on
                                // the unique index and leaves tokens live.
                                $record->deleteWithTombstone();
                                $deleted++;
                            } else {
                                $skipped++;
                            }
                        }

                        Notification::make()
                            ->title($skipped > 0
                                ? "Deleted {$deleted}, skipped {$skipped} (admin/self accounts are never bulk-deleted)."
                                : "Deleted {$deleted} user(s).")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            // Admin accounts live in Super Admin → Admins. Leaving them out of
            // the query also means an admin's edit URL here is a 404.
            ->where('role', '!=', UserRole::Admin)
            // The name column shows the phone from the role profile row.
            ->with(['salesman', 'vendor', 'customer']);
    }

    /** @return array<string, string> the roles this screen manages — not admin */
    public static function roleOptions(): array
    {
        return collect(UserRole::cases())
            ->reject(fn (UserRole $role) => $role === UserRole::Admin)
            ->mapWithKeys(fn (UserRole $role) => [$role->value => ucfirst($role->value)])
            ->all();
    }

    /**
     * How many accounts are waiting on a decision.
     *
     * Signups are invisible otherwise — nothing else tells an admin someone
     * registered, now that no mail goes out and approval is the only way in
     * (SPEC section 3.1). A user left pending because nobody noticed cannot
     * use the product at all.
     *
     * Excludes soft-deleted rows, which getEloquentQuery() would otherwise
     * include here and inflate the count with accounts nobody can act on.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()
            ->whereNull('deleted_at')
            ->where('approval_status', ApprovalStatus::Pending)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * The tabs on a user's page: Account, then Media.
     *
     * People used to list Vendors, Vendor Verification and Media
     * Moderation as separate sidebar entries, which split one subject —
     * a person, their vendor record, and the content they uploaded —
     * across three screens with nothing but an id to carry between them.
     *
     * The vendor record is NOT a tab of its own: its fields and its
     * verification both live in the Account tab, next to the account they
     * belong to (see the Vendor profile fieldset and
     * vendorVerificationSection). Only the uploads are separate, because
     * a moderation queue is a list of its own and does not belong inside
     * a form.
     *
     * The Media tab hides itself for non-vendor roles — an empty one on a
     * salesman reads as missing data rather than as not applicable.
     *
     * @return array<int, class-string>
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn (?int $paise): string => Number::currency(($paise ?? 0) / 100, 'INR');

        return $infolist->schema([
            InfoSection::make('Basic details')
                ->collapsible()
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('email')
                        ->formatStateUsing(fn (User $record): string => $record->isTombstoned()
                            ? '(deleted account) — was '.$record->original_email
                            : $record->email)
                        ->copyable(),
                    TextEntry::make('role')
                        ->badge()
                        ->formatStateUsing(fn (UserRole $state): string => ucfirst($state->value)),
                    TextEntry::make('phone')
                        ->state(fn (User $record): ?string => static::profilePhone($record))
                        ->placeholder('—'),
                ]),

            InfoSection::make('Account status')
                ->collapsible()
                ->columns(4)
                ->schema([
                    TextEntry::make('approval_status')
                        ->label('Approval')
                        ->badge()
                        ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                        ->color(fn (ApprovalStatus $state): string => $state->color()),
                    TextEntry::make('approvalDecidedBy.name')->label('Decided by')->placeholder('—'),
                    TextEntry::make('approval_decided_at')->label('Decided on')->dateTime('d M Y, H:i')->placeholder('—'),
                    IconEntry::make('must_change_password')->label('Must change password')->boolean(),
                    TextEntry::make('approval_note')
                        ->label('Rejection reason')
                        ->columnSpanFull()
                        ->visible(fn (User $record): bool => $record->isRejected()),
                    TextEntry::make('deleted_at')
                        ->label('Deleted on')
                        ->dateTime('d M Y, H:i')
                        ->visible(fn (User $record): bool => $record->trashed()),
                ]),

            InfoSection::make('Salesman details')
                ->collapsible()
                ->columns(4)
                ->visible(fn (User $record): bool => $record->role === UserRole::Salesman && $record->salesman !== null)
                ->schema([
                    TextEntry::make('salesman.employee_code')->label('Employee code')->placeholder('—'),
                    TextEntry::make('salesman.region')->label('Region')->placeholder('—'),
                    TextEntry::make('salesman.monthly_target_paise')->label('Monthly target')->formatStateUsing($money),
                    TextEntry::make('salesman.is_active')
                        ->label('Salesman status')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ]),

            InfoSection::make('Vendor details')
                ->collapsible()
                ->columns(4)
                ->visible(fn (User $record): bool => $record->role === UserRole::Vendor && $record->vendor !== null)
                ->schema([
                    TextEntry::make('vendor.business_name')->label('Business name'),
                    TextEntry::make('vendor.owner_name')->label('Owner')->placeholder('—'),
                    TextEntry::make('vendor.city')->label('City')->placeholder('—'),
                    TextEntry::make('vendor.status')->label('Vendor status')->badge(),
                    IconEntry::make('vendor.is_suspended')->label('Suspended')->boolean(),
                    TextEntry::make('vendor.createdBySalesman.user.name')->label('Added by')->placeholder('Self-registered'),
                    TextEntry::make('vendor.address')->label('Address')->columnSpan(2)->placeholder('—'),
                ]),

            InfoSection::make('Customer details')
                ->collapsible()
                ->columns(4)
                ->visible(fn (User $record): bool => $record->role === UserRole::Customer && $record->customer !== null)
                ->schema([
                    TextEntry::make('customer.pincode')->label('Pincode')->placeholder('—'),
                    TextEntry::make('customer_location')
                        ->label('Saved location')
                        ->state(fn (User $record): ?string => $record->customer?->latitude !== null
                            ? $record->customer->latitude.', '.$record->customer->longitude
                            : null)
                        ->placeholder('Not set'),
                ]),

            InfoSection::make('Record')
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('Added on')->dateTime('d M Y, H:i'),
                    TextEntry::make('updated_at')->label('Last modified')->dateTime('d M Y, H:i'),
                ]),
        ]);
    }

    /** The phone lives on the role's profile row, not on users. */
    public static function profilePhone(User $record): ?string
    {
        return match ($record->role) {
            UserRole::Salesman => $record->salesman?->phone,
            UserRole::Vendor => $record->vendor?->phone,
            UserRole::Customer => $record->customer?->phone,
            default => null,
        };
    }

    public static function getRelations(): array
    {
        return [
            UserResource\RelationManagers\VendorDetailsRelationManager::class,
            UserResource\RelationManagers\VendorDocumentsRelationManager::class,
            UserResource\RelationManagers\PortfolioMediaRelationManager::class,
        ];
    }

    /**
     * The compulsory KYC documents a vendor still lacks Verified — empty
     * for anyone else. Approve is greyed out while this is not empty.
     *
     * @return array<int, string>
     */
    public static function missingKyc(User $record): array
    {
        if ($record->role !== UserRole::Vendor || $record->vendor === null) {
            return [];
        }

        return app(VendorKycService::class)->missingCompulsory($record->vendor);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
