<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaModerationResource\Pages;
use App\Models\Media;
use App\Services\MediaModerationService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Media Moderation Queue (SPEC section 5.10) — vendor portfolio photos/
 * videos awaiting Approve/Reject before going live (task 4.5).
 *
 * A dedicated queue, not a general media browser — the base query below is
 * load-bearing, same as VendorVerificationResource's own queue scoping.
 *
 * GRID, not a table: these are images/videos, a table of filenames would be
 * useless for actually moderating content. This is the first Filament
 * resource in the app using contentGrid() — everything else here (scoping,
 * actions, policy shape) mirrors VendorVerificationResource exactly.
 *
 * No Create page and no Delete action: media rows are never created or
 * deleted through this queue, only transitioned between pending and
 * approved/rejected. Same "the omission is the enforcement" shape
 * CategoryResource/VendorVerificationResource already use.
 */
class MediaModerationResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media Moderation';

    protected static ?string $modelLabel = 'media';

    protected static ?string $navigationGroup = 'People';

    /**
     * Hidden from the sidebar: moderation now happens on the Media tab of
     * the uploading vendor's user page, where the rest of that vendor's
     * context is. The resource stays registered — its approve/reject
     * actions are reused verbatim by that tab.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager loaded explicitly: `mediable` is a morph relation, which
        // Filament's automatic dot-notation eager-loading doesn't reliably
        // infer the target class for.
        return parent::getEloquentQuery()->pending()->with(['mediable', 'subcategory']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                'xl' => 4,
            ])
            ->defaultSort('created_at')
            ->columns([
                ViewColumn::make('path')
                    ->label('')
                    ->view('filament.tables.columns.media-preview'),

                TextColumn::make('mediable.business_name')
                    ->label('Vendor')
                    ->weight('bold'),

                TextColumn::make('subcategory.name')
                    ->label('Subcategory')
                    ->placeholder('—'),

                TextColumn::make('type')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime(),
            ])
            ->actions([
                static::approveAction(),
                static::rejectAction(),
            ])
            ->bulkActions([
                // No bulk approve/reject — each piece of content needs its
                // own look, even more so than the KYC text fields
                // VendorVerificationResource's same stance covers.
            ])
            ->emptyStateHeading('Nothing to moderate')
            ->emptyStateDescription('Vendor portfolio uploads land here once submitted (SPEC section 3 item 5).');
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => Auth::user()?->can('moderate', Media::class) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Approve this media?')
            ->modalDescription('It becomes visible on the vendor\'s public portfolio immediately.')
            ->action(function (Media $record) {
                app(MediaModerationService::class)->approve($record, Auth::user());

                Notification::make()
                    ->title('Media approved')
                    ->success()
                    ->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('moderate', Media::class) ?? false)
            ->modalHeading('Reject this media?')
            ->form([
                Textarea::make('reason')
                    ->label('Rejection reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (Media $record, array $data) {
                app(MediaModerationService::class)->reject($record, Auth::user(), $data['reason']);

                Notification::make()
                    ->title('Media rejected')
                    ->danger()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaModerations::route('/'),
        ];
    }
}
