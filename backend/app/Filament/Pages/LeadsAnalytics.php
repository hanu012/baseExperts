<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LeadsOverTimeChart;
use App\Models\Category;
use App\Models\Lead;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Leads & Call Analytics (SPEC section 5 item 11) — every lead across
 * every vendor, filterable by vendor/category/zone/date range, plus
 * LeadsOverTimeChart above the table. The first custom Filament Page
 * in this app (everything else is a Resource or a widget on the stock
 * Dashboard) since this doesn't fit the CRUD shape a Resource assumes
 * — leads are read-only here, there's no Create/Edit page to pair it
 * with.
 *
 * Gated by Permission::LeadsViewAny via LeadPolicy — raw per-lead
 * detail (which customer contacted which vendor, when) is closer in
 * sensitivity to the already-gated Resources than to the Dashboard's
 * ungated aggregate counts.
 */
class LeadsAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Leads & Analytics';

    protected static ?string $navigationGroup = 'Analytics';

    protected static string $view = 'filament.pages.leads-analytics';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Lead::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [LeadsOverTimeChart::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Lead::query()->with(['vendor', 'customer.user', 'subcategory.category', 'zone'])
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('vendor.business_name')
                    ->label('Vendor')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('customer.user.name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('subcategory.category.name')
                    ->label('Category'),

                TextColumn::make('subcategory.name')
                    ->label('Subcategory'),

                TextColumn::make('zone.name')
                    ->label('Zone')
                    ->placeholder('—'),

                TextColumn::make('channel')
                    ->badge(),

                // Approximate: tap Call → back in the customer app, so it
                // includes ringing. Under 30 seconds usually means the call
                // was not answered.
                TextColumn::make('call_duration_seconds')
                    ->label('Call length (approx.)')
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : sprintf('~%d:%02d', intdiv($state, 60), $state % 60))
                    ->color(fn (?int $state): ?string => $state !== null && $state < 30 ? 'warning' : null)
                    ->tooltip('Approximate — from tapping Call to returning to the app, so it includes ringing time.'),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->relationship('vendor', 'business_name')
                    ->searchable(),

                SelectFilter::make('zone_id')
                    ->label('Zone')
                    ->relationship('zone', 'name')
                    ->searchable(),

                // No direct category_id column on leads — only
                // subcategory_id — so this resolves through the
                // subcategory -> category chain rather than a plain
                // relationship() filter.
                SelectFilter::make('category_id')
                    ->label('Category')
                    // get() before pluck(): a query-builder pluck returns
                    // the raw per-language JSON rather than the name.
                    ->options(fn () => Category::query()->orderBy('name')->get()->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'subcategory',
                            fn (Builder $subcategoryQuery) => $subcategoryQuery->where('category_id', $data['value'])
                        );
                    }),

                SelectFilter::make('channel')
                    ->options(['call' => 'Call', 'whatsapp' => 'WhatsApp']),

                // Likely unanswered: the customer was back in the app
                // within half a minute.
                Filter::make('short_calls')
                    ->label('Short calls (under 30 sec)')
                    ->query(fn (Builder $query) => $query
                        ->where('channel', 'call')
                        ->whereNotNull('call_duration_seconds')
                        ->where('call_duration_seconds', '<', 30)),

                Filter::make('date_range')
                    ->label('Date range')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', Carbon::parse($date))
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', Carbon::parse($date))
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.Carbon::parse($data['from'])->toFormattedDateString();
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.Carbon::parse($data['until'])->toFormattedDateString();
                        }

                        return $indicators;
                    }),
            ]);
    }
}
