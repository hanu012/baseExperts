<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\ApiErrorLogResource\Pages;
use App\Models\ApiErrorLog;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Super Admin → API error log: failed API calls recorded by the LogApiErrors
 * middleware — an HTTP error status, or a reply that said
 * `"success": false` (those keep their 2xx/3xx status and show amber).
 *
 * Exists only while System settings → Error log is on — off, the menu entry
 * is gone and the page cannot be opened (nothing new is recorded either).
 * Rows are written by the middleware, so there is no create or edit; a
 * super-admin can delete rows or clear the log (ApiErrorLogPolicy).
 */
class ApiErrorLogResource extends Resource
{
    protected static ?string $model = ApiErrorLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'API error log';

    protected static ?string $modelLabel = 'API error';

    protected static ?string $pluralModelLabel = 'API errors';

    protected static ?string $slug = 'api-error-log';

    protected static ?string $navigationGroup = 'System';

    public static function isEnabled(): bool
    {
        return (bool) Setting::get('api_error_log_enabled', false);
    }

    public static function canAccess(): bool
    {
        return static::isEnabled() && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isEnabled() && parent::shouldRegisterNavigation();
    }

    /** Server errors in the last 24 hours, as the menu badge. */
    public static function getNavigationBadge(): ?string
    {
        $count = ApiErrorLog::query()
            ->where('status', '>=', 500)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->recordAction('details')
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i:s')
                    ->description(fn (ApiErrorLog $record): string => $record->created_at->diffForHumans())
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    // A 2xx/3xx here means the reply said success: false.
                    ->tooltip(fn (int $state): ?string => $state < 400 ? 'Reply said "success": false with this status' : null)
                    ->color(fn (int $state): string => match (true) {
                        $state >= 500 => 'danger',
                        $state < 400, $state === 422 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('path')
                    ->label('Request')
                    ->searchable()
                    ->formatStateUsing(fn (ApiErrorLog $record): string => $record->method.' '.$record->path)
                    ->wrap(),

                TextColumn::make('message')
                    ->label('Error')
                    ->searchable(['message', 'error_code', 'exception_class'])
                    ->description(fn (ApiErrorLog $record): ?string => $record->error_code ?? $record->exception_class)
                    ->limit(90)
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('user.email')
                    ->label('User')
                    ->placeholder('Not signed in')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('duration_ms')
                    ->label('Time')
                    ->suffix(' ms')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options([
                        'server' => 'Server errors (5xx)',
                        'client' => 'Request errors (4xx)',
                        'reported' => 'Failed with a success status ("success": false)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'server' => $query->where('status', '>=', 500),
                        'client' => $query->whereBetween('status', [400, 499]),
                        'reported' => $query->where('status', '<', 400),
                        default => $query,
                    }),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => ApiErrorLog::query()
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),

                SelectFilter::make('method')
                    ->options([
                        'GET' => 'GET',
                        'POST' => 'POST',
                        'PUT' => 'PUT',
                        'PATCH' => 'PATCH',
                        'DELETE' => 'DELETE',
                    ]),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->actions([
                Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (ApiErrorLog $record): string => "{$record->status} · {$record->method} {$record->path}")
                    ->modalContent(fn (ApiErrorLog $record) => view('filament.api-error-log.details', ['log' => $record]))
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No API errors recorded')
            ->emptyStateDescription('Failed API calls appear here while System settings → Error log is on.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiErrorLogs::route('/'),
        ];
    }
}
