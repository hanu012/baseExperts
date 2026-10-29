<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VendorResource\Pages;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Support\ActiveSubscriptionSummary;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Number;
use WeakMap;

/**
 * All vendors, and what each one is actually subscribed to (SPEC section
 * 5.2) — the read-only counterpart to VendorVerificationResource, which is
 * a decision queue scoped to `pending_verification` and therefore cannot
 * answer "what did this active vendor buy?".
 *
 * READ-ONLY BY DESIGN: no Create, Edit or Delete.
 *   - Creating a vendor means creating its User, its profile and usually a
 *     subscription together — that transaction belongs to the salesman
 *     flow (VendorDraftService) and User Management, not to a second
 *     half-complete path here.
 *   - Editing a vendor's services/zones is quota-governed
 *     (SubscriptionService + ServiceSelectionValidator); a plain Filament
 *     form would bypass every one of those rules.
 *   - Approving/rejecting stays in the verification queue, so there is one
 *     place where that decision is made and audited.
 * Same "the omission is the enforcement" shape CategoryResource documents.
 *
 * The subscription block is rendered from [ActiveSubscriptionSummary], the
 * same source `GET /vendors/me` and the salesman's detail screen read, so
 * the panel cannot disagree with the apps about a vendor's quota.
 */
class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Vendors';

    protected static ?string $navigationGroup = 'People';

    /**
     * Hidden from the sidebar: a vendor is reached through its user now,
     * on the Vendor tab of that user's page, so People lists Users alone.
     * The resource stays registered — its pages, policy and tests are
     * still the implementation behind that tab, and its URLs resolve.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'business_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'subscriptions.plan'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('business_name')
            ->columns([
                TextColumn::make('business_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('owner_name')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'grace' => 'warning',
                        'expired', 'rejected' => 'danger',
                        'pending_verification', 'pending_payment' => 'info',
                        default => 'gray',
                    }),

                // Resolved per row rather than joined: currentActiveSubscription()
                // is the single definition of "active or still in grace", and
                // duplicating that window as a query here is how the list and
                // the detail page start disagreeing.
                TextColumn::make('plan')
                    ->label('Plan')
                    ->state(fn (Vendor $record): ?string => $record->currentActiveSubscription()?->plan->name)
                    ->badge()
                    ->color('primary')
                    ->placeholder('Not subscribed'),

                TextColumn::make('expires')
                    ->label('Expires')
                    ->state(fn (Vendor $record): ?string => $record->currentActiveSubscription()
                        ?->end_date->toFormattedDateString())
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending_payment' => 'Pending payment',
                        'pending_verification' => 'Pending verification',
                        'active' => 'Active',
                        'grace' => 'Grace',
                        'expired' => 'Expired',
                        'rejected' => 'Rejected',
                    ]),

                SelectFilter::make('plan')
                    ->label('Plan')
                    // get() before pluck(): a query-builder pluck returns
                    // the raw per-language JSON rather than the name.
                    ->options(fn (): array => Plan::query()->orderBy('name')->get()->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $planId) => $q->whereHas(
                            'subscriptions',
                            fn (Builder $s) => $s->where('plan_id', $planId)->where('end_date', '>=', now()),
                        ),
                    )),

                Filter::make('unsubscribed')
                    ->label('Not subscribed')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'subscriptions',
                        fn (Builder $s) => $s->where('end_date', '>=', now()),
                    )),

                TrashedFilter::make(),
            ])
            ->actions([ViewAction::make()])
            ->bulkActions([
                // Nothing to bulk-apply: this resource only reads.
            ])
            ->emptyStateHeading('No vendors yet')
            ->emptyStateDescription('Vendors arrive from the salesman flow or self-registration.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Business')
                ->columns(2)
                ->schema([
                    TextEntry::make('business_name'),
                    TextEntry::make('owner_name'),
                    TextEntry::make('phone'),
                    TextEntry::make('user.email')->label('Email'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('createdBySalesman.user.name')
                        ->label('Added by')
                        ->placeholder('Self-registered'),
                    TextEntry::make('address')->columnSpanFull()->placeholder('—'),
                ]),

            Section::make('Subscription')
                ->description('Live plan, quota and selections — the same figures the vendor and salesman apps show.')
                ->columns(3)
                ->schema([
                    TextEntry::make('plan_name')
                        ->label('Plan')
                        ->state(fn (Vendor $record): string => static::summary($record)['plan_name'] ?? 'Not subscribed')
                        ->badge()
                        ->color(fn (Vendor $record): string => static::summary($record) === null ? 'gray' : 'success'),

                    TextEntry::make('end_date')
                        ->label('Expires')
                        ->state(fn (Vendor $record): string => static::summary($record)['end_date'] ?? '—'),

                    TextEntry::make('days_remaining')
                        ->label('Days remaining')
                        ->state(function (Vendor $record): string {
                            $days = static::summary($record)['days_remaining'] ?? null;

                            if ($days === null) {
                                return '—';
                            }

                            // Negative means the vendor is inside the grace
                            // window past end_date, which currentActiveSubscription()
                            // still counts as active — surface that rather
                            // than printing a bare "-3".
                            return $days < 0 ? abs($days).' days into grace' : (string) $days;
                        })
                        ->badge()
                        ->color(function (Vendor $record): string {
                            $days = static::summary($record)['days_remaining'] ?? null;

                            return match (true) {
                                $days === null => 'gray',
                                $days < 0 => 'danger',
                                $days <= 7 => 'warning',
                                default => 'success',
                            };
                        }),

                    ...static::quotaEntries(),
                ]),

            // One row per payment on the current subscription: the plan
            // purchase first, then any add-ons bought on top of it.
            Section::make('Payments')
                ->description('How the current subscription was paid for.')
                ->visible(fn (Vendor $record): bool => static::currentPayments($record)->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('current_payments')
                        ->hiddenLabel()
                        ->state(fn (Vendor $record): Collection => static::currentPayments($record))
                        ->columns(5)
                        ->schema([
                            TextEntry::make('for')
                                ->label('For')
                                ->state(fn (Payment $record): string => $record->id === (int) $record->subscription->payments()->min('id')
                                    ? 'Plan'
                                    : 'Add-on'),

                            // Every entry here computes its value with state():
                            // inside this RepeatableEntry a plain column read
                            // ('mode', 'amount_paise') renders empty.
                            TextEntry::make('payment_mode')
                                ->label('Payment method')
                                ->badge()
                                ->state(fn (Payment $record): string => match ($record->mode) {
                                    'cash' => 'Cash',
                                    'online' => 'Online',
                                    'free' => 'Free trial'.($record->subscription->free_trial_days
                                        ? ' ('.$record->subscription->free_trial_days.' days)'
                                        : ''),
                                    default => ucfirst((string) $record->mode),
                                })
                                ->color(fn (Payment $record): string => match ($record->mode) {
                                    'free' => 'gray',
                                    'cash' => 'warning',
                                    default => 'success',
                                }),

                            TextEntry::make('payment_amount')
                                ->label('Amount')
                                ->state(fn (Payment $record): string => Number::currency($record->amount_paise / 100, 'INR')),

                            TextEntry::make('payment_status')
                                ->label('Status')
                                ->state(fn (Payment $record): string => match (true) {
                                    $record->mode === 'free' => 'No payment',
                                    $record->mode === 'online' => 'Paid online',
                                    $record->admin_verified_at !== null => 'Cash verified '.$record->admin_verified_at->format('d M Y'),
                                    default => 'Cash collected — awaiting admin verification',
                                }),

                            TextEntry::make('collected_by')
                                ->label('Collected by')
                                ->state(fn (Payment $record): ?string => $record->collectedBySalesman?->user?->name)
                                ->placeholder('—'),
                        ]),
                ]),

            Section::make('Selected services and zones')
                ->description('What this vendor actually picked, within the quota above.')
                ->schema([
                    static::itemsEntry('categories', 'Categories'),
                    static::itemsEntry('subcategories', 'Subcategories'),
                    static::itemsEntry('zones', 'Coverage zones'),
                ]),

            Section::make('Shop photo & KYC documents')
                ->columns(2)
                ->schema([
                    // Both thumbnails click through to the stored original:
                    // what is rendered here is far too small to check a
                    // document against a business. A plain link rather than
                    // an in-page lightbox because CLAUDE.md bans
                    // third-party CDN scripts in this panel, and the
                    // browser already displays an image perfectly well.
                    //
                    // URLs come from TracksFileDisk::fileUrl(), which
                    // resolves against the row's own `disk` and returns
                    // null for an empty path — Storage::url(null) would
                    // otherwise produce a link to the bucket root.
                    ImageEntry::make('shop_photo_path')
                        ->label('Shop photo')
                        ->disk(fn (Vendor $record): string => $record->disk ?? config('filesystems.default'))
                        ->url(fn (Vendor $record): ?string => $record->fileUrl($record->shop_photo_path))
                        ->openUrlInNewTab()
                        ->tooltip('Open full size')
                        ->placeholder('Not provided'),

                    // The KYC documents themselves (VendorKycService) are on
                    // the Documents tab, with their files and the Verify /
                    // Re-upload actions; this is the at-a-glance summary.
                    TextEntry::make('kyc_summary')
                        ->label('KYC documents')
                        ->state(fn (Vendor $record): array => $record->documents()
                            ->with('documentType')
                            ->whereHas('documentType', fn ($q) => $q->where('is_active', true))
                            ->get()
                            ->map(fn (VendorDocument $d): string => $d->documentType->name.' — '.$d->statusLabel())
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('None yet — see the Documents tab'),
                ]),
        ]);
    }

    /**
     * One resolve per record per render. [ActiveSubscriptionSummary] runs
     * several queries, and the infolist asks for the same vendor a dozen
     * times across its entries — without this each page view would multiply
     * that out.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private static array $summaryCache = [];

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Keyed by the record object, so it lives exactly as long as one render
     * — unlike an id-keyed static array, it cannot serve stale rows to a
     * later request or test that reuses the id.
     *
     * @var WeakMap<Vendor, Collection<int, Payment>>|null
     */
    private static ?WeakMap $paymentsCache = null;

    /**
     * Payments on the vendor's current subscription, oldest first, so the
     * first row is the plan purchase.
     *
     * @return Collection<int, Payment>
     */
    private static function currentPayments(Vendor $vendor): Collection
    {
        static::$paymentsCache ??= new WeakMap();

        return static::$paymentsCache[$vendor] ??= $vendor->currentActiveSubscription()
            ?->payments()
            ->with(['subscription', 'collectedBySalesman.user'])
            ->orderBy('id')
            ->get()
            ?? new Collection();
    }

    private static function summary(Vendor $vendor): ?array
    {
        return static::$summaryCache[$vendor->id] ??= ActiveSubscriptionSummary::for($vendor);
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function quotaEntries(): array
    {
        $resources = [
            'categories' => 'Categories',
            'subcategories' => 'Subcategories',
            'zones' => 'Zones',
            'photos' => 'Photos',
            'videos' => 'Videos',
        ];

        $entries = [];

        foreach ($resources as $key => $label) {
            $entries[] = TextEntry::make("quota_{$key}")
                ->label($label)
                ->state(function (Vendor $record) use ($key): string {
                    $quota = static::summary($record)['quota'][$key] ?? null;

                    return $quota === null ? '—' : "{$quota['used']} / {$quota['max']}";
                })
                ->badge()
                ->color(function (Vendor $record) use ($key): string {
                    $quota = static::summary($record)['quota'][$key] ?? null;

                    if ($quota === null) {
                        return 'gray';
                    }

                    return $quota['used'] >= $quota['max'] ? 'warning' : 'primary';
                });
        }

        return $entries;
    }

    private static function itemsEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make("items_{$key}")
            ->label($label)
            ->state(function (Vendor $record) use ($key): array {
                $items = static::summary($record)['items'][$key] ?? [];

                return array_column($items, 'name');
            })
            ->badge()
            ->placeholder('None selected')
            ->columnSpanFull();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVendors::route('/'),
            'view' => Pages\ViewVendor::route('/{record}'),
        ];
    }
}
