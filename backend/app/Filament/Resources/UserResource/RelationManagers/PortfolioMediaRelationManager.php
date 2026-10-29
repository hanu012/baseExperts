<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\UserRole;
use App\Filament\Resources\MediaModerationResource;
use App\Models\Media;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The "Media" tab on a user's page — portfolio moderation, moved off its
 * own sidebar entry.
 *
 * Moderating from a standalone queue meant deciding on a photo with no
 * sight of whose it was or what else they had uploaded; here the vendor's
 * record and their other media are one tab away.
 *
 * Approve/reject are [MediaModerationResource]'s own actions, reused so
 * both routes go through MediaModerationService rather than growing a
 * second copy of the transition.
 *
 * Unlike that queue this tab is NOT filtered to pending: the point of
 * looking at a specific vendor is seeing everything they have posted.
 * A status filter is provided instead.
 */
class PortfolioMediaRelationManager extends RelationManager
{
    protected static string $relationship = 'portfolioMedia';

    protected static ?string $title = 'Media';

    protected static ?string $icon = 'heroicon-o-photo';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User && $ownerRecord->role === UserRole::Vendor;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('media.created_at', 'desc')
            ->columns([
                // A 56pt square, not the full-bleed image the standalone
                // queue used: at full size two uploads filled the screen
                // and the row's own details were pushed out of view. The
                // full image is one tap away via Preview below.
                ImageColumn::make('path')
                    ->label('')
                    ->disk(fn (Media $record): string => $record->disk ?? config('filesystems.default'))
                    ->height(56)
                    ->width(56)
                    ->square()
                    // A video has no still to show, so the thumbnail slot
                    // stays empty rather than rendering a broken image.
                    ->visible(true)
                    ->defaultImageUrl(fn (Media $record): ?string => $record->type === 'video'
                        ? 'data:image/svg+xml;base64,'.base64_encode(
                            '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56">'
                            .'<rect width="56" height="56" fill="#1e293b"/>'
                            .'<path d="M22 18l16 10-16 10z" fill="#94a3b8"/></svg>'
                        )
                        : null),

                TextColumn::make('subcategory.name')
                    ->label('Subcategory')
                    ->placeholder('—'),

                TextColumn::make('type')
                    ->badge(),

                TextColumn::make('moderation_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('moderation_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                static::previewAction(),
                MediaModerationResource::approveAction()
                    ->visible(fn (Media $record): bool => $record->moderation_status !== 'approved'
                        && (auth()->user()?->can('moderate', Media::class) ?? false)),
                MediaModerationResource::rejectAction()
                    ->visible(fn (Media $record): bool => $record->moderation_status !== 'rejected'
                        && (auth()->user()?->can('moderate', Media::class) ?? false)),
            ])
            ->headerActions([
                // Uploads come from the vendor app, never from here.
            ])
            ->emptyStateHeading('No uploads')
            ->emptyStateDescription('Portfolio photos and videos from this vendor appear here.');
    }

    /**
     * Opens the file at full size in a modal.
     *
     * A modal rather than a new tab because moderating is a sequence —
     * look, decide, next — and bouncing through browser tabs for each one
     * makes a queue of twenty uploads miserable.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-magnifying-glass-plus')
            ->color('gray')
            ->visible(fn (Media $record): bool => $record->fileUrl() !== null)
            ->modalHeading(fn (Media $record): string => $record->type === 'video'
                ? 'Video preview'
                : 'Photo preview')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (Media $record): HtmlString {
                $url = e($record->fileUrl() ?? '');

                if ($record->type === 'video') {
                    return new HtmlString(
                        '<video controls preload="metadata" style="width:100%;border-radius:0.5rem;">'
                        ."<source src=\"{$url}\"></video>"
                    );
                }

                return new HtmlString(
                    "<img src=\"{$url}\" alt=\"Portfolio upload\" "
                    .'style="width:100%;height:auto;border-radius:0.5rem;" />'
                );
            });
    }
}
