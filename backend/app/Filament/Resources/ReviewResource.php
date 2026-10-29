<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReviewResource\Pages;
use App\Models\Category;
use App\Models\Review;
use App\Models\Subcategory;
use App\Services\ReviewModerationService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Review Management (SPEC section 5 item 6, task 5.5) — hide/unhide,
 * view vendor replies, a fraud-signal filter.
 *
 * Filters: vendor, rating, category and subcategory (from the review's
 * lead — the service the customer contacted the vendor about),
 * visibility, replied or not, and the fraud signal. Clicking a row opens
 * the whole review.
 *
 * A table, not a grid: reviews are text, unlike MediaModerationResource's
 * images/video. Every review is shown, not just a "pending" subset —
 * this is a management/browse list, not a triage queue, since admins
 * need to see and unhide already-hidden reviews too.
 *
 * No Create/Edit/Delete pages: admin never authors, edits, or deletes a
 * review — only hides/unhides one via the actions below. Same "the
 * omission is the enforcement" shape as MediaModerationResource.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review';

    protected static ?string $navigationGroup = 'People';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer.user', 'vendor', 'lead.subcategory.category']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->recordAction('view')
            ->columns([
                TextColumn::make('vendor.business_name')
                    ->label('Vendor')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Review $record): ?string => $record->customer?->user?->name
                        ? 'by '.$record->customer->user->name
                        : null),

                // The service the customer contacted the vendor about —
                // the review's lead knows it, the review row does not.
                TextColumn::make('lead.subcategory.name')
                    ->label('Service')
                    ->placeholder('—')
                    ->description(fn (Review $record): ?string => $record->lead?->subcategory?->category?->name),

                TextColumn::make('rating')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->color(fn (int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state === 3 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('comment')
                    ->wrap()
                    ->limit(100)
                    ->placeholder('—')
                    ->searchable(),

                // Hidden by default to keep the table narrow — the full reply
                // is in the View popup, and the "Vendor reply" filter finds
                // replied or unreplied reviews. Still switchable on from the
                // column toggle.
                TextColumn::make('vendor_reply')
                    ->label('Vendor reply')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('No reply')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_hidden')
                    ->label('Hidden')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),

                TextColumn::make('created_at')
                    ->label('Posted')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->relationship('vendor', 'business_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('rating')
                    ->label('Rating')
                    ->options([
                        5 => '★★★★★ 5',
                        4 => '★★★★☆ 4',
                        3 => '★★★☆☆ 3',
                        2 => '★★☆☆☆ 2',
                        1 => '★☆☆☆☆ 1',
                    ]),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    // Translatable names: read through the model, sorted here.
                    ->options(fn (): array => Category::query()->get()->sortBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lead.subcategory', fn (Builder $sub) => $sub->where('category_id', $data['value']))
                        : $query),

                SelectFilter::make('subcategory')
                    ->label('Subcategory')
                    ->searchable()
                    ->options(fn (): array => Subcategory::query()
                        ->with('category')
                        ->get()
                        ->mapWithKeys(fn (Subcategory $sub): array => [
                            $sub->id => trim(($sub->category?->name ?? '').' › '.$sub->name, ' ›'),
                        ])
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lead', fn (Builder $lead) => $lead->where('subcategory_id', $data['value']))
                        : $query),

                TernaryFilter::make('is_hidden')
                    ->label('Visibility')
                    ->placeholder('All')
                    ->trueLabel('Hidden only')
                    ->falseLabel('Visible only'),

                TernaryFilter::make('replied')
                    ->label('Vendor reply')
                    ->placeholder('All')
                    ->trueLabel('Replied')
                    ->falseLabel('Not replied')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('vendor_reply'),
                        false: fn (Builder $query): Builder => $query->whereNull('vendor_reply'),
                    ),

                // SPEC section 5 item 6's fraud signal: a review created
                // more than 30 days after its own lead. The write path
                // (StoreReviewRequest) already rejects this at creation
                // time, so a match here means something bypassed it —
                // seeded data, a bug, or direct DB manipulation — worth
                // an admin's attention, not a normal filtering need.
                Filter::make('lead_older_than_30_days')
                    ->label('Lead older than 30 days (fraud signal)')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'lead',
                        fn (Builder $lead) => $lead->whereRaw(
                            'leads.created_at < reviews.created_at - INTERVAL 30 DAY'
                        )
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->actions([
                static::viewAction(),
                static::hideAction(),
                static::unhideAction(),
            ])
            ->bulkActions([
                // No bulk hide/unhide — each review needs its own look,
                // same stance MediaModerationResource takes on approve/
                // reject.
            ])
            ->emptyStateHeading('No reviews yet');
    }

    /**
     * The whole review in one place — the table cuts the comment and the
     * reply short, and an admin deciding whether to hide one needs both in
     * full, plus why it was hidden if it already is.
     */
    public static function viewAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (Review $record): string => 'Review of '.($record->vendor?->business_name ?? 'vendor'))
            ->modalContent(fn (Review $record) => view('filament.reviews.detail', ['review' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function hideAction(): Action
    {
        return Action::make('hide')
            ->label('Hide')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (Review $record): bool => ! $record->is_hidden && (Auth::user()?->can('hide', Review::class) ?? false))
            ->modalHeading('Hide this review?')
            ->modalDescription('It stops appearing on the vendor\'s public detail page and no longer counts toward their rating.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (Review $record, array $data) {
                app(ReviewModerationService::class)->hide($record, Auth::user(), $data['reason']);

                Notification::make()->title('Review hidden')->success()->send();
            });
    }

    public static function unhideAction(): Action
    {
        return Action::make('unhide')
            ->label('Unhide')
            ->icon('heroicon-o-eye')
            ->color('success')
            ->visible(fn (Review $record): bool => $record->is_hidden && (Auth::user()?->can('hide', Review::class) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Unhide this review?')
            ->action(function (Review $record) {
                app(ReviewModerationService::class)->unhide($record);

                Notification::make()->title('Review unhidden')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviews::route('/'),
        ];
    }
}
