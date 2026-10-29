<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaGalleryResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\PortfolioMediaRelationManager;
use App\Models\Category;
use App\Models\Media;
use App\Models\Subcategory;
use App\Models\Vendor;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Media Gallery — every photo and video vendors have uploaded to their
 * portfolios, in one browsable grid.
 *
 * Uploads go live without review (VendorPortfolioController), so this is
 * where an admin looks over what customers are seeing and takes something
 * down if needed. Filters: vendor, photo/video, category, subcategory and
 * status.
 *
 * Read-only apart from the status actions: no create, edit or delete —
 * uploads come from the vendor app, and Take down (reject) hides a file
 * without destroying it. Take down / Restore reuse MediaModerationResource's
 * actions, so every route goes through MediaModerationService.
 */
class MediaGalleryResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media Gallery';

    protected static ?string $modelLabel = 'media';

    protected static ?string $pluralModelLabel = 'media';

    protected static ?string $slug = 'media-gallery';

    protected static ?string $navigationGroup = 'Content';

    public static function getEloquentQuery(): Builder
    {
        // Portfolio media only. `mediable` is a morph relation, eager loaded
        // explicitly for the same reason MediaModerationResource does.
        return parent::getEloquentQuery()
            ->where('mediable_type', (new Vendor)->getMorphClass())
            ->with(['mediable', 'subcategory.category']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([12, 24, 48, 96])
            ->defaultPaginationPageOption(24)
            ->columns([
                Stack::make([
                    ViewColumn::make('path')
                        ->label('')
                        ->view('filament.tables.columns.media-preview'),

                    TextColumn::make('mediable.business_name')
                        ->label('Vendor')
                        ->weight('bold')
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                            'mediable',
                            [Vendor::class],
                            fn (Builder $vendor) => $vendor->where('business_name', 'like', "%{$search}%"),
                        )),

                    TextColumn::make('subcategory.name')
                        ->label('Service')
                        ->placeholder('No service')
                        ->description(fn (Media $record): ?string => $record->subcategory?->category?->name),

                    Split::make([
                        TextColumn::make('type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'video' ? 'Video' : 'Photo')
                            ->color(fn (string $state): string => $state === 'video' ? 'info' : 'gray'),

                        TextColumn::make('moderation_status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::statusLabel($state))
                            ->color(fn (?string $state): string => match ($state) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'warning',
                            }),
                    ]),

                    TextColumn::make('created_at')
                        ->label('Uploaded')
                        ->since()
                        ->color('gray')
                        ->size(TextColumn\TextColumnSize::ExtraSmall),
                ])->space(2),
            ])
            ->filters([
                SelectFilter::make('vendor')
                    ->label('Vendor')
                    ->searchable()
                    ->options(fn (): array => Vendor::query()
                        ->whereHas('media')
                        ->orderBy('business_name')
                        ->pluck('business_name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('mediable_id', $data['value'])
                        : $query),

                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'image' => 'Photos',
                        'video' => 'Videos',
                    ]),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    // Names are translatable (Spatie), so they are read
                    // through the model and sorted here, not in SQL.
                    ->options(fn (): array => Category::query()
                        ->get()
                        ->sortBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('subcategory', fn (Builder $sub) => $sub->where('category_id', $data['value']))
                        : $query),

                SelectFilter::make('subcategory_id')
                    ->label('Subcategory')
                    ->searchable()
                    // "AC Repair › Gas Filling": the category in front tells
                    // apart same-named services under different categories.
                    ->options(fn (): array => Subcategory::query()
                        ->with('category')
                        ->get()
                        ->mapWithKeys(fn (Subcategory $sub): array => [
                            $sub->id => trim(($sub->category?->name ?? '').' › '.$sub->name, ' ›'),
                        ])
                        ->sort()
                        ->all()),

                SelectFilter::make('moderation_status')
                    ->label('Status')
                    ->options([
                        'approved' => 'Live',
                        'rejected' => 'Taken down',
                        'pending' => 'Pending',
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->actions([
                PortfolioMediaRelationManager::previewAction(),

                MediaModerationResource::rejectAction()
                    ->label('Take down')
                    ->modalHeading('Take this down?')
                    ->modalDescription('Customers stop seeing it straight away. The vendor sees "Removed by admin", and the file stays stored.')
                    ->visible(fn (Media $record): bool => $record->moderation_status !== 'rejected'
                        && (auth()->user()?->can('moderate', Media::class) ?? false)),

                MediaModerationResource::approveAction()
                    ->label('Restore')
                    ->modalHeading('Restore this media?')
                    ->modalDescription('It becomes visible on the vendor\'s public portfolio again.')
                    ->visible(fn (Media $record): bool => $record->moderation_status === 'rejected'
                        && (auth()->user()?->can('moderate', Media::class) ?? false)),

                Action::make('vendor')
                    ->label('Vendor')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->visible(fn (Media $record): bool => $record->mediable instanceof Vendor && $record->mediable->user_id !== null)
                    // Vendor management lives on the owning user's page.
                    ->url(fn (Media $record): string => UserResource::getUrl('edit', ['record' => $record->mediable->user_id])),
            ])
            ->bulkActions([
                // None: each file deserves its own look before it is taken down.
            ])
            ->emptyStateIcon('heroicon-o-photo')
            ->emptyStateHeading('No media')
            ->emptyStateDescription('Photos and videos vendors upload to their portfolios appear here.');
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Live',
            'rejected' => 'Taken down',
            default => 'Pending',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaGallery::route('/'),
        ];
    }
}
