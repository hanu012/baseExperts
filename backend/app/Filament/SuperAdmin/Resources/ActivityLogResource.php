<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\ActivityLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Super Admin → Activity log: who changed what, and when — every row of
 * audit_logs (approvals, payments, settings, status changes, deletions,
 * admin permission changes…). Written by RecordsAuditLog and a few explicit
 * writeAuditLog() calls; nothing here can edit or delete a row.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'activity';

    protected static ?string $slug = 'activity-log';

    protected static ?int $navigationSort = 2;

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
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i:s')
                    ->description(fn (AuditLog $record): string => $record->created_at->diffForHumans())
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Who')
                    ->placeholder('System')
                    ->description(fn (AuditLog $record): ?string => $record->user?->email)
                    ->searchable(),

                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => match ($state) {
                        'deleted' => 'danger',
                        'created' => 'success',
                        'restored' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('auditable_type')
                    ->label('Record')
                    ->formatStateUsing(fn (AuditLog $record): string => static::recordLabel($record)),

                TextColumn::make('changed')
                    ->label('What changed')
                    ->state(fn (AuditLog $record): string => implode(', ', array_keys($record->new_values ?? [])) ?: '—')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->options(fn (): array => User::query()
                        ->whereIn('id', AuditLog::query()->select('user_id')->whereNotNull('user_id')->distinct())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->map(fn (string $action) => ucfirst(str_replace('_', ' ', $action)))
                        ->all()),

                SelectFilter::make('auditable_type')
                    ->label('Record type')
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('auditable_type')
                        ->distinct()
                        ->orderBy('auditable_type')
                        ->pluck('auditable_type', 'auditable_type')
                        ->map(fn (string $type) => class_basename($type))
                        ->all()),

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
                    ->modalHeading(fn (AuditLog $record): string => ucfirst(str_replace('_', ' ', $record->action)).' · '.static::recordLabel($record))
                    ->modalContent(fn (AuditLog $record) => view('filament.super-admin.activity-details', ['log' => $record]))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No activity recorded yet');
    }

    /** "Vendor #12" */
    public static function recordLabel(AuditLog $record): string
    {
        return $record->auditable_type === null
            ? '—'
            : class_basename($record->auditable_type).' #'.$record->auditable_id;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
