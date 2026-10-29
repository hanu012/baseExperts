<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentReconciliationResource\Pages;
use App\Models\Payment;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * Cash-collection reconciliation (SPEC section 5 item 9 / section
 * 5.9) — the moment PaymentService's design has been waiting on since
 * task 3.4: `payViaCash()` always leaves `admin_verified_at` null, and
 * nothing before this resource ever set it.
 *
 * A dedicated queue, not general Payment management — the base query
 * below is load-bearing, same shape VendorVerificationResource's own
 * queue scoping. No Create/Edit/Delete pages: payments are never
 * authored or edited here, only transitioned via verify().
 */
class PaymentReconciliationResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cash Reconciliation';

    protected static ?string $modelLabel = 'payment';

    protected static ?string $navigationGroup = 'Finance';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('mode', 'cash')
            ->whereNull('admin_verified_at')
            ->with(['subscription.vendor', 'collectedBySalesman.user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('subscription.vendor.business_name')
                    ->label('Vendor')
                    ->searchable(),

                TextColumn::make('amount_paise')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => Number::currency($state / 100, 'INR')),

                TextColumn::make('collectedBySalesman.user.name')
                    ->label('Collected by')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Collected on')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                static::verifyAction(),
            ])
            ->bulkActions([
                // No bulk verify — each collection needs its own look,
                // same stance VendorVerificationResource takes on
                // approve/reject.
            ])
            ->emptyStateHeading('Nothing to reconcile')
            ->emptyStateDescription('Cash payments land here until an admin confirms the money was actually collected.');
    }

    public static function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Mark verified')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => Auth::user()?->can('verify', Payment::class) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Mark this cash payment as reconciled?')
            ->modalDescription('Confirms the money was actually collected. This is independent of the matching commission\'s own payout status — verifying a payment does not mark its commission paid.')
            ->action(function (Payment $record) {
                $record->update(['admin_verified_at' => now()]);

                Notification::make()
                    ->title('Payment verified')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentReconciliations::route('/'),
        ];
    }
}
