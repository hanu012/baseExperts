<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportResource\Pages;
use App\Models\Report;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reported vendors (SPEC section 4 item 10 / section 5.15) — a minimal,
 * read-only list so a submitted report is actually visible to someone.
 * Deliberately not the full Support Tickets module: no status, no
 * assignment, no resolve action. See ReportController's docblock and
 * PROGRESS.md's Before Launch Checklist for what Phase 6 needs to add
 * on top of this.
 *
 * No Create/Edit/Delete pages, same "the omission is the enforcement"
 * shape as MediaModerationResource/ReviewResource — reports are written
 * only by the customer-facing endpoint, never authored or edited here.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $modelLabel = 'report';

    protected static ?string $navigationGroup = 'People';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer.user', 'vendor']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.user.name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('vendor.business_name')
                    ->label('Vendor')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('reason')
                    ->wrap()
                    ->limit(150),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No reports yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReports::route('/'),
        ];
    }
}
