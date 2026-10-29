<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\TranslatableInput;
use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Support\RecordStatus;
use App\Models\Plan;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Subscription plans and their quotas (SPEC section 5.4).
 *
 * NO DELETE ACTION — but for a different reason than categories, subcategories
 * and zones. SPEC section 10 covers those three because subscription_items
 * carries no foreign key. Plans are referenced by subscriptions.plan_id, which
 * IS a real foreign key with ON DELETE RESTRICT: the database already refuses,
 * so a delete action would only ever surface a raw integrity error. Deactivate
 * instead — existing subscriptions keep running, the plan stops being offered.
 *
 * The quota is edited inside this form rather than as its own resource: it is
 * 1:1 with the plan and meaningless apart from it.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Plan')
                ->columns(2)
                ->schema([
                    TranslatableInput::make('name', 'Name', function (string $language) {
                        $input = TextInput::make($language)->maxLength(255);

                        if ($language !== 'en') {
                            return $input;
                        }

                        return $input
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Set $set) {
                                if ($operation === 'create') {
                                    $set('../slug', Str::slug((string) $state));
                                }
                            });
                    }, required: true)->columnSpanFull(),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Used by the apps. Changing it breaks existing references.'),

                    // Rupees in the field, paise in the column. The conversion
                    // lives in Plan::rupeesToPaise(), which parses the decimal
                    // as a string rather than multiplying a float — Rs 1.15
                    // would otherwise store as 114 paise. See the note there.
                    TextInput::make('price_rupees')
                        ->label('Price')
                        ->prefix('₹')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText('Stored as integer paise. Enter rupees, e.g. 999.00')
                        // Stays dehydrated so it reaches mutateFormDataBefore*,
                        // which converts it to price_paise and unsets it. With
                        // dehydrated(false) the hook receives nothing and the
                        // price silently saves as 0.
                        ->afterStateHydrated(function (TextInput $component, ?Plan $record) {
                            $component->state($record?->priceInRupees() ?? '0.00');
                        }),

                    TextInput::make('duration_days')
                        ->label('Duration (days)')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->helperText('The server computes end_date as start + this many days.'),

                    TranslatableInput::make(
                        'description',
                        'Description',
                        fn (string $language) => Textarea::make($language)->rows(2),
                    )->columnSpanFull(),

                    // Starting status only; afterwards "Change status", with a
                    // reason. Hidden on edit, not disabled — CLAUDE.md.
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->hiddenOn('edit')
                        ->helperText('Inactive plans are hidden from new subscriptions. Existing ones keep running.'),
                ]),

            Fieldset::make('Quota')
                // 1:1 hasOne — Filament creates or updates the plan_quotas row
                // alongside the plan, inside the same save.
                ->relationship('quota')
                ->columns(3)
                ->schema([
                    TextInput::make('max_categories')->label('Max categories')
                        ->required()->numeric()->minValue(0)->default(1),

                    TextInput::make('max_subcategories')->label('Max subcategories')
                        ->required()->numeric()->minValue(0)->default(1),

                    TextInput::make('max_zones')->label('Max zones')
                        ->required()->numeric()->minValue(0)->default(1),

                    TextInput::make('max_photos')->label('Max photos')
                        ->required()->numeric()->minValue(0)->default(5),

                    TextInput::make('max_videos')->label('Max videos')
                        ->required()->numeric()->minValue(0)->default(0),

                    TextInput::make('priority_rank')
                        ->label('Priority rank')
                        ->required()->numeric()->minValue(0)->default(0)
                        ->helperText('Higher ranks first in customer search, before rating and recency.'),
                ]),

            // Add-on unit pricing (SPEC section 3 item 6 / section 6, task
            // 4.7) — "+2 categories" without changing the base plan. Kept
            // in the SAME Fieldset/relationship('quota') as the limits
            // above, not a second one — two Fieldsets both mapped to the
            // same hasOne relationship risk fighting over which one's
            // save wins. Entered directly in paise, unlike the plan's own
            // price field above — a deliberate simplification (no
            // rupees-conversion hook added for these five fields); see
            // PROGRESS.md's task 4.7 entry.
            Fieldset::make('Add-on pricing (paise per unit)')
                ->relationship('quota')
                ->columns(3)
                ->schema([
                    TextInput::make('addon_price_per_category_paise')
                        ->label('Per category')
                        ->required()->numeric()->minValue(0)->default(0),

                    TextInput::make('addon_price_per_subcategory_paise')
                        ->label('Per subcategory')
                        ->required()->numeric()->minValue(0)->default(0),

                    TextInput::make('addon_price_per_zone_paise')
                        ->label('Per zone')
                        ->required()->numeric()->minValue(0)->default(0),

                    TextInput::make('addon_price_per_photo_paise')
                        ->label('Per photo')
                        ->required()->numeric()->minValue(0)->default(0),

                    TextInput::make('addon_price_per_video_paise')
                        ->label('Per video')
                        ->required()->numeric()->minValue(0)->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            // A row click opens the read-only view, not the edit form.
            ->recordUrl(null)
            ->recordAction('view')
            ->columns([
                TextColumn::make('name')
                    ->searchable(query: \App\Support\TranslatableColumn::search('name'))
                    ->sortable(query: \App\Support\TranslatableColumn::sort('name'))
                    ->weight('bold')
                    ->description(fn (Plan $record): ?string => $record->description),

                TextColumn::make('price_paise')
                    ->label('Price')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Number::currency($state / 100, 'INR'))
                    ->description(fn (Plan $record): string => 'per '.$record->duration_days.' days'),

                ViewColumn::make('quota')
                    ->label('Quota')
                    ->view('filament.tables.columns.quota-card'),

                // Active is the operationally useful number; the all-time
                // total is what ON DELETE RESTRICT actually blocks on, so it
                // is shown whenever it differs — a plan reading "0 active"
                // with 51 expired is still undeletable, and deactivating it
                // still affects those customers' renewals.
                TextColumn::make('in_use')
                    ->label('In use')
                    ->state(fn (Plan $record): string => static::inUseLabel($record))
                    ->badge()
                    ->color(fn (Plan $record): string => $record->activeSubscriptionCount() > 0 ? 'success' : 'gray')
                    ->tooltip('Subscriptions currently running on this plan, and the all-time total.'),

                RecordStatus::column(),

                TextColumn::make('updated_at')
                    ->label('Last modified')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading(fn (Plan $record): string => $record->name)
                    ->extraModalFooterActions(fn (ViewAction $action): array => [
                        $action->makeModalAction('edit')
                            ->label('Edit')
                            ->icon('heroicon-o-pencil-square')
                            ->color('primary')
                            ->url(static::getUrl('edit', ['record' => $action->getRecord()])),
                    ]),
                EditAction::make(),
                RecordStatus::configure(
                    TableAction::make('changeStatus'),
                    fn (Plan $record): string => static::statusEffects($record),
                ),
                // No DeleteAction — see the class docblock.
            ])
            ->bulkActions([
                // No DeleteBulkAction either.
            ])
            ->emptyStateHeading('No plans yet')
            ->emptyStateDescription('A plan sets what a vendor may select and for how long. Add one before onboarding vendors.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $paise = fn (?int $state): string => Number::currency(($state ?? 0) / 100, 'INR');

        return $infolist->schema([
            InfoSection::make('Basic details')
                ->collapsible()
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('price_paise')->label('Price')->formatStateUsing($paise),
                    TextEntry::make('duration_days')->label('Duration')->suffix(' days'),
                    RecordStatus::entry(),
                    TextEntry::make('slug'),
                    TextEntry::make('sort_order')->label('Display order'),
                    TextEntry::make('description')->columnSpan(2)->placeholder('—'),
                ]),

            InfoSection::make('Limits')
                ->collapsible()
                ->columns(3)
                ->schema([
                    TextEntry::make('quota.max_categories')->label('Max categories'),
                    TextEntry::make('quota.max_subcategories')->label('Max subcategories'),
                    TextEntry::make('quota.max_zones')->label('Max zones'),
                    TextEntry::make('quota.max_photos')->label('Max photos'),
                    TextEntry::make('quota.max_videos')->label('Max videos'),
                    TextEntry::make('quota.priority_rank')
                        ->label('Priority rank')
                        ->helperText('Higher ranks are listed first in customer search.'),
                ]),

            InfoSection::make('Add-on pricing')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextEntry::make('quota.addon_price_per_category_paise')->label('Per extra category')->formatStateUsing($paise),
                    TextEntry::make('quota.addon_price_per_subcategory_paise')->label('Per extra subcategory')->formatStateUsing($paise),
                    TextEntry::make('quota.addon_price_per_zone_paise')->label('Per extra zone')->formatStateUsing($paise),
                    TextEntry::make('quota.addon_price_per_photo_paise')->label('Per extra photo')->formatStateUsing($paise),
                    TextEntry::make('quota.addon_price_per_video_paise')->label('Per extra video')->formatStateUsing($paise),
                ]),

            InfoSection::make('Usage')
                ->collapsible()
                ->schema([
                    TextEntry::make('in_use')
                        ->label('Subscriptions')
                        ->state(fn (Plan $record): string => static::inUseLabel($record)),
                ]),

            RecordStatus::recordSection(),
        ]);
    }

    public static function statusEffects(Plan $record): string
    {
        $active = $record->activeSubscriptionCount();

        return 'Active: offered to salesmen and vendors for new subscriptions. '
            .'Inactive: no longer offered. '
            .($active === 0
                ? 'No subscriptions are running on it.'
                : $active.' running '.Str::plural('subscription', $active).' keep going until they expire either way.');
    }

    /**
     * "3 active" when that is the whole story, "3 active / 51 all time" when
     * history differs.
     */
    public static function inUseLabel(Plan $plan): string
    {
        $active = $plan->activeSubscriptionCount();
        $total = $plan->totalSubscriptionCount();

        if ($total === 0) {
            return 'Not used';
        }

        if ($active === $total) {
            return $active.' active';
        }

        return $active.' active / '.$total.' all time';
    }

    public static function getEloquentQuery(): Builder
    {
        // The quota card reads it per row; eager-load rather than N+1.
        return parent::getEloquentQuery()->with('quota');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
