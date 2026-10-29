<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\SuperAdmin\Resources\AdminResource\Pages;
use App\Models\User;
use App\Services\AdminAccountService;
use App\Services\TwoFactorService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Super Admin → Admins: every admin account, and what each may do in /admin.
 * Moved here from the Users screen, which now only manages salesmen,
 * vendors and customers.
 *
 * Same users table and UserPolicy as before; the extra rules (always one
 * super-admin left, nobody edits or deletes themselves) are in
 * AdminAccountService.
 *
 * The permission controls are HIDDEN on your own row, not disabled — a
 * disabled field still round-trips through the request (CLAUDE.md).
 */
class AdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Admins';

    protected static ?string $modelLabel = 'admin';

    protected static ?string $slug = 'admins';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', UserRole::Admin);
    }

    public static function form(Form $form): Form
    {
        $notSelf = fn (?User $record): bool => $record === null || ! $record->is(Auth::user());

        return $form->schema([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Trashed rows count, as on the Users screen: the
                        // unique index does not exempt them.
                        ->unique(ignoreRecord: true)
                        ->helperText('Their sign-in for the admin panel.'),
                ]),

            Section::make('Access')
                ->description('What this admin can do in the Admin panel. Super-admins can do everything, and are the only ones who can open this Super Admin panel.')
                ->visible($notSelf)
                ->schema([
                    Toggle::make('is_super_admin')
                        ->label('Super-admin (full access)')
                        ->live()
                        ->helperText('Super-admins must set up two-step login the first time they open the Super Admin panel.'),

                    Select::make('template')
                        ->label('Start from a template')
                        ->options(collect(AdminAccountService::TEMPLATES)->map(fn (array $t) => $t['label']))
                        ->placeholder('Pick one to tick its permissions below')
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set) {
                            if ($state !== null) {
                                $set('permissions', AdminAccountService::TEMPLATES[$state]['permissions']);
                            }
                        })
                        ->hidden(fn (Get $get): bool => (bool) $get('is_super_admin')),

                    CheckboxList::make('permissions')
                        ->options(collect(Permission::cases())->mapWithKeys(fn (Permission $p) => [
                            $p->value => ucfirst(explode('.', $p->value)[0]).' — '.$p->label(),
                        ]))
                        ->columns(3)
                        ->bulkToggleable()
                        ->helperText('Nothing ticked means no access — permissions fail closed.')
                        ->hidden(fn (Get $get): bool => (bool) $get('is_super_admin')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): ?string => $record->is(Auth::user()) ? 'You' : null),

                TextColumn::make('email')->searchable()->copyable(),

                TextColumn::make('access')
                    ->state(fn (User $record): string => $record->isSuperAdmin()
                        ? 'Super-admin'
                        : count($record->permissions ?? []).' permissions')
                    ->badge()
                    ->color(fn (User $record): string => $record->isSuperAdmin() ? 'primary' : 'gray'),

                TextColumn::make('two_factor')
                    ->label('Two-step login')
                    ->state(fn (User $record): string => match (true) {
                        $record->hasTwoFactorEnabled() => 'On',
                        $record->isSuperAdmin() => 'Not set up yet',
                        default => 'Not needed',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'On' => 'success',
                        'Not set up yet' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('updated_at')
                    ->label('Last modified')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                EditAction::make()->visible(fn (User $record): bool => ! $record->is(Auth::user())),

                ActionGroup::make([
                    Action::make('signOutEverywhere')
                        ->label('Sign out everywhere')
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->requiresConfirmation()
                        ->modalDescription('Ends every panel session and app sign-in of this admin. They must sign in again.')
                        ->visible(fn (User $record): bool => ! $record->is(Auth::user()))
                        ->action(function (User $record) {
                            app(AdminAccountService::class)->signOutEverywhere($record);
                            Notification::make()->title("{$record->name} was signed out everywhere")->success()->send();
                        }),

                    Action::make('resetTwoFactor')
                        ->label('Reset two-step login')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('For a lost phone. They set it up again the next time they open the Super Admin panel, and are signed out now.')
                        ->visible(fn (User $record): bool => $record->hasTwoFactorEnabled() && ! $record->is(Auth::user()))
                        ->action(function (User $record) {
                            app(TwoFactorService::class)->reset($record);
                            app(AdminAccountService::class)->signOutEverywhere($record);
                            Notification::make()->title("Two-step login reset for {$record->name}")->success()->send();
                        }),

                    Action::make('deleteAdmin')
                        ->label('Delete admin')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('The account is deleted and signed out everywhere. Its history stays in the activity log.')
                        ->disabled(fn (User $record): bool => app(AdminAccountService::class)->deletionBlocker(Auth::user(), $record) !== null)
                        ->tooltip(fn (User $record): ?string => app(AdminAccountService::class)->deletionBlocker(Auth::user(), $record))
                        ->action(function (User $record) {
                            $blocker = app(AdminAccountService::class)->deletionBlocker(Auth::user(), $record);

                            if ($blocker !== null) {
                                Notification::make()->title('Cannot delete')->body($blocker)->danger()->send();

                                return;
                            }

                            $record->deleteWithTombstone();
                            Notification::make()->title('Admin deleted')->success()->send();
                        }),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No admins');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdmins::route('/'),
            'create' => Pages\CreateAdmin::route('/create'),
            'edit' => Pages\EditAdmin::route('/{record}/edit'),
        ];
    }
}
