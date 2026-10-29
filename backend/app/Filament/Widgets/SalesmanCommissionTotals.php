<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Commission & Payouts page (SPEC section 5 item 9) — pending/paid
 * totals side by side per salesman. `TableWidget` (Filament's own
 * table-backed widget base, same `table()`/`InteractsWithTable`
 * shape every Resource and `LeadsAnalytics` already use) is the
 * right fit for "one row per salesman, two totals at once" — no
 * hand-rolled Blade table needed.
 *
 * A salesman with zero commissions doesn't appear at all: the query
 * only groups over rows that exist, same "the row exists only where
 * there's something to show" shape as an empty-state table rather
 * than a zero-padded row nobody needs to see.
 */
class SalesmanCommissionTotals extends TableWidget
{
    protected static ?string $heading = 'Commission totals by salesman';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Commission::query()
                    // salesman_id is selected twice: once under its own
                    // name so the salesman() belongsTo relation can
                    // still resolve its foreign key, and once aliased
                    // as `id` so Filament's row-key mechanism (which
                    // reads $record->getKey(), i.e. the `id` column)
                    // has something real and unique to key each row on
                    // — a plain GROUP BY with no `id` in the select
                    // list would leave every resulting row's id null.
                    ->selectRaw('salesman_id, salesman_id as id')
                    ->selectRaw("SUM(CASE WHEN status = 'pending' THEN amount_paise ELSE 0 END) as pending_total_paise")
                    ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount_paise ELSE 0 END) as paid_total_paise")
                    ->with('salesman.user')
                    ->groupBy('salesman_id')
            )
            ->columns([
                TextColumn::make('salesman.user.name')
                    ->label('Salesman')
                    ->weight('bold'),

                TextColumn::make('pending_total_paise')
                    ->label('Pending')
                    ->formatStateUsing(fn (int $state): string => Number::currency($state / 100, 'INR')),

                TextColumn::make('paid_total_paise')
                    ->label('Paid')
                    ->formatStateUsing(fn (int $state): string => Number::currency($state / 100, 'INR')),
            ])
            ->paginated(false);
    }
}
