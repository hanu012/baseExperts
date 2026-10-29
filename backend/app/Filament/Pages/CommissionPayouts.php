<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\SalesmanCommissionTotals;
use App\Models\Commission;
use App\Models\Salesman;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * Commission & Payouts (SPEC section 5 item 9) — every commission
 * across every salesman, mark-as-paid with a captured payout
 * reference. A custom Page, not a Resource, so the per-salesman
 * totals widget above the table can render at all — Filament's stock
 * Resource List page has no header-widget slot (confirmed by reading
 * its own Blade view), same reasoning `LeadsAnalytics` is built this
 * way.
 */
class CommissionPayouts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Commission & Payouts';

    protected static ?string $navigationGroup = 'Finance';

    protected static string $view = 'filament.pages.commission-payouts';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Commission::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [SalesmanCommissionTotals::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Commission::query()->with(['salesman.user', 'subscription.vendor'])
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('salesman.user.name')
                    ->label('Salesman')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('subscription.vendor.business_name')
                    ->label('Vendor')
                    ->placeholder('—'),

                TextColumn::make('amount_paise')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => Number::currency($state / 100, 'INR')),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('paid_at')
                    ->label('Paid on')
                    ->dateTime()
                    ->placeholder('—'),

                TextColumn::make('payout_reference')
                    ->label('Reference')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Earned')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                // A direct relationship() on 'salesman', not the nested
                // 'salesman.user' — Salesman itself has no name column
                // (that lives on User), so the option label is
                // overridden instead of filtering through a dotted
                // relationship path.
                SelectFilter::make('salesman_id')
                    ->label('Salesman')
                    ->relationship('salesman', 'employee_code', fn (Builder $query) => $query->with('user'))
                    ->getOptionLabelFromRecordUsing(fn (Salesman $record) => $record->user?->name ?? $record->employee_code)
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                $this->markPaidAction(),
            ]);
    }

    private function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label('Mark as paid')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(
                fn (Commission $record): bool => $record->status === 'pending'
                    && (Auth::user()?->can('markPaid', Commission::class) ?? false)
            )
            ->modalHeading('Mark this commission as paid?')
            ->modalDescription('Records who authorized the payout and when — capture the reference for whatever transferred the money (UTR, cheque number, etc.).')
            ->form([
                TextInput::make('payout_reference')
                    ->label('Payout reference')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Commission $record, array $data) {
                $record->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payout_reference' => $data['payout_reference'],
                ]);

                Notification::make()->title('Commission marked as paid')->success()->send();
            });
    }
}
