<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ZoneMap;
use App\Filament\Resources\ZoneResource\Pages;
use App\Filament\Support\RecordStatus;
use App\Filament\Support\SafeDelete;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Actions\DeleteAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Validation\Rules\Unique;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Zone management (SPEC sections 5.3, 8 and 11) — the MAIN zones screen.
 *
 * One `zones` table, two screens: this one lists main zones (no parent),
 * SubZoneResource extends it and lists sub-zones (parent set). The form,
 * table and pages are shared; isSubZoneScreen() switches the few parts that
 * differ (the parent field, the parent column, the Zone Areas tab).
 *
 * DELETE ONLY WHEN UNUSED (SafeDelete): refused while the zone has sub-zones
 * (parent_id is ON DELETE SET NULL, which would silently promote them) or
 * any subscription covers it (subscription_items.item_id has no foreign
 * key, SPEC section 10). Otherwise it is set to Draft instead.
 *
 * DRAFT WORKFLOW (SPEC section 11): polygon is NOT NULL and always indexed,
 * so a zone cannot be saved without a boundary. Zones therefore start
 * inactive — draw a rough outline now, refine it, then switch Active on.
 * Only active zones take part in customer matching.
 */
class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Zones';

    protected static ?string $modelLabel = 'zone';

    protected static ?string $recordTitleAttribute = 'name';

    /** True on SubZoneResource. */
    public static function isSubZoneScreen(): bool
    {
        return false;
    }

    /** Main zones here; SubZoneResource narrows to sub-zones instead. */
    public static function scopeToScreen(Builder $query): Builder
    {
        return static::isSubZoneScreen()
            ? $query->whereNotNull('parent_id')
            : $query->whereNull('parent_id');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToScreen(parent::getEloquentQuery());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Zone')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Basic Information')->schema(static::basicInformationSchema()),
                    Tab::make('Zone Configuration')->schema(static::configurationSchema()),
                    // Sub-zones cannot have areas of their own (two levels).
                    Tab::make('Zone Areas')
                        ->visible(! static::isSubZoneScreen())
                        ->schema([
                            View::make('filament.zones.areas'),
                        ]),
                ]),
        ]);
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    private static function basicInformationSchema(): array
    {
        $isSubZone = static::isSubZoneScreen();

        return [
            Grid::make(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Zone name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, $state, Set $set) {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        // Scoped to the parent, matching the composite unique
                        // index on (parent_id, slug): two different cities can
                        // each have a "central" sub-zone.
                        ->unique(
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                                ->where('parent_id', $get('parent_id')),
                            ignoreRecord: true,
                        )
                        ->helperText('Unique within the parent zone.'),

                    TextInput::make('code')
                        ->label('Zone code')
                        ->maxLength(50)
                        ->regex('/^[A-Za-z0-9\-]+$/')
                        ->unique(ignoreRecord: true)
                        // Optional on create, where Zone fills in ZONE-012
                        // style; required on edit so an existing code
                        // cannot be blanked out.
                        ->required(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper(trim($state)) : null)
                        ->placeholder('ZONE-AMD-001')
                        ->helperText('Unique identifier. Letters, numbers and dashes. Leave blank to get one automatically.'),

                    // Only on the Sub-zones screen, where it is required. On
                    // the main Zones screen it is hidden (not disabled), so it
                    // is never in the form state and a main zone stays one.
                    Select::make('parent_id')
                        ->label('Main zone')
                        ->visible($isSubZone)
                        ->required($isSubZone)
                        // Prefilled from "Add area" on a zone's Zone Areas
                        // tab. Only a default: the rules below still
                        // validate whatever id arrives.
                        ->default(fn (): ?int => request()->integer('parent_id') ?: null)
                        ->relationship(
                            name: 'parent',
                            titleAttribute: 'name',
                            // Only top-level zones are offered: SPEC section 8
                            // caps the hierarchy at two levels (city ->
                            // sub-zone). A zone also cannot be its own parent.
                            modifyQueryUsing: fn ($query, ?Zone $record) => $query
                                ->whereNull('parent_id')
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey())),
                        )
                        ->searchable()
                        ->preload()
                        // Filtering the options is UX; these rules are the
                        // enforcement. A Livewire request can carry any id,
                        // so the constraint cannot live in the dropdown alone.
                        ->rules([
                            fn (?Zone $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record) {
                                if (blank($value)) {
                                    return;
                                }

                                $parent = Zone::find($value);

                                if ($parent === null) {
                                    return;
                                }

                                // Forward direction, as SPEC section 8 states:
                                // the chosen parent must itself be top-level.
                                if ($parent->parent_id !== null) {
                                    $fail(
                                        "\"{$parent->name}\" is already a sub-zone. Zones go two levels deep "
                                        .'(city → sub-zone), so a sub-zone cannot have children of its own.'
                                    );

                                    return;
                                }

                                // A first child turns a standalone leaf into
                                // a parent, which is never matched — every
                                // vendor subscribed to it would silently drop
                                // out of search. Refused while any live
                                // subscription still points at it.
                                $parentIsLeaf = ! $parent->children()
                                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($parentIsLeaf) {
                                    $liveSubscribers = $parent->subscribingVendors()
                                        ->whereIn('subscription_status', ['active', 'grace'])
                                        ->count();

                                    if ($liveSubscribers > 0) {
                                        $fail(
                                            "\"{$parent->name}\" has no sub-zones yet and {$liveSubscribers} vendor "
                                            .'subscription(s) cover it directly. Adding a sub-zone would make it a parent '
                                            .'zone, which customers are never matched to, so those vendors would disappear '
                                            .'from search. Move them to sub-zones first.'
                                        );

                                        return;
                                    }
                                }

                                // Reverse direction: giving a parent to a zone
                                // that already has children would build the
                                // same three-level tree from the other end.
                                if ($record !== null && $record->children()->exists()) {
                                    $fail(
                                        "\"{$record->name}\" already has sub-zones, so it cannot itself become "
                                        .'a sub-zone. Zones go two levels deep (city → sub-zone).'
                                    );
                                }
                            },
                        ])
                        ->helperText('The main zone this area belongs to, e.g. Ahmedabad for Gota. Zones go two levels deep — a sub-zone cannot have sub-zones of its own.'),

                    TextInput::make('pincode')
                        ->maxLength(10)
                        ->helperText('Fallback match when GPS is denied or the location falls outside every boundary.'),

                    Placeholder::make('zone_type')
                        ->label('Zone type')
                        ->hiddenOn('create')
                        ->content(fn (?Zone $record): string => $record ? static::typeLabel($record) : ''),

                    // Starting status only. After creation it changes through
                    // "Change status", which asks for a reason — hidden on edit
                    // rather than disabled, so it is not in the form state at all.
                    Toggle::make('is_active')
                        ->hiddenOn('edit')
                        // SPEC section 11: zones are drafts until switched on.
                        ->default(false)
                        ->label('Active')
                        ->helperText('Only active zones are matched against customer locations. Leave off while the boundary is still rough.'),
                ]),
        ];
    }

    /**
     * Shape picker, map, and — for circles — the centre and radius fields
     * the map reads and writes (see ZoneMap).
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function configurationSchema(): array
    {
        $isCircle = fn (Get $get): bool => $get('shape_type') === Zone::SHAPE_CIRCLE;

        return [
            Select::make('shape_type')
                ->label('Zone shape')
                ->options([
                    Zone::SHAPE_POLYGON => 'Polygon',
                    Zone::SHAPE_CIRCLE => 'Circle',
                ])
                ->default(Zone::SHAPE_POLYGON)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->helperText('Polygon: trace the boundary point by point. Circle: a centre and a radius in km.'),

            ZoneMap::make('polygon_points')
                ->label('')
                ->required(fn (Get $get): bool => ! $isCircle($get))
                ->rules([
                    fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get, $isCircle) {
                        if ($isCircle($get)) {
                            return;
                        }

                        if (! is_array($value) || count($value) < 3) {
                            $fail('Draw a boundary with at least 3 points.');
                        }
                    },
                ])
                ->validationMessages(['required' => 'Draw the zone boundary on the map.'])
                ->dehydrated(),

            Grid::make(3)
                ->visible($isCircle)
                ->schema([
                    TextInput::make('center_lat')
                        ->label('Center latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->required($isCircle)
                        ->live(onBlur: true)
                        ->helperText('Set by clicking the map, or type it.'),

                    TextInput::make('center_lng')
                        ->label('Center longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->required($isCircle)
                        ->live(onBlur: true),

                    TextInput::make('radius_km')
                        ->label('Radius (KM)')
                        ->numeric()
                        ->minValue(0.05)
                        ->maxValue(200)
                        ->required($isCircle)
                        ->live(onBlur: true)
                        ->helperText('Or drag the edge of the circle.'),
                ]),
        ];
    }

    /**
     * Turns the form's shape fields into the `polygon` geometry, for both
     * CreateZone and EditZone.
     *
     * A circle is saved as its centre and radius AND as a polygon
     * approximating it, so ZoneMatcher keeps working off `polygon` alone.
     * A polygon zone clears any circle left over from an earlier shape.
     */
    public static function prepareBoundary(array $data): array
    {
        $points = $data['polygon_points'] ?? [];
        unset($data['polygon_points']);

        if (($data['shape_type'] ?? Zone::SHAPE_POLYGON) === Zone::SHAPE_CIRCLE) {
            $points = Zone::circlePoints(
                (float) $data['center_lat'],
                (float) $data['center_lng'],
                (float) $data['radius_km'],
            );
        } else {
            $data['shape_type'] = Zone::SHAPE_POLYGON;
            $data['center_lat'] = null;
            $data['center_lng'] = null;
            $data['radius_km'] = null;
        }

        $data['polygon'] = Zone::polygonExpression($points);

        return $data;
    }

    public static function table(Table $table): Table
    {
        $isSubZone = static::isSubZoneScreen();

        return $table
            ->defaultSort('name')
            // A row click opens the read-only view, not the edit form.
            ->recordUrl(null)
            ->recordAction('view')
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'code'])
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Zone $record): string => $record->code ?? $record->slug),

                TextColumn::make('shape_type')
                    ->label('Shape')
                    ->formatStateUsing(fn (Zone $record): string => static::shapeLabel($record))
                    ->toggleable(),

                TextColumn::make('zone_type')
                    ->label('Type')
                    ->state(fn (Zone $record): string => static::typeLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'City (has sub-zones)' ? 'info' : 'gray'),

                TextColumn::make('parent.name')
                    ->label('Main zone')
                    ->visible($isSubZone)
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                // Opens the Sub-zones screen filtered to this zone.
                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Sub-zones')
                    ->visible(! $isSubZone)
                    ->badge()
                    ->url(fn (Zone $record): string => SubZoneResource::getUrl('index', [
                        'tableFilters' => ['parent_id' => ['value' => $record->getKey()]],
                    ])),

                TextColumn::make('pincode')
                    ->searchable()
                    ->placeholder('—'),

                // Same purpose as on CategoryResource: shows why no delete is
                // offered, and the cost of deactivating, before the toggle is
                // flipped.
                TextColumn::make('in_use')
                    ->label('In use')
                    ->state(fn (Zone $record): int => $record->countSubscriptionReferences())
                    ->badge()
                    // Tapping the badge answers the question it raises:
                    // "in use by whom?". Only offered where there is
                    // something to show — a modal reading "nothing here"
                    // is a worse answer than an unclickable badge.
                    ->action(
                        TableAction::make('viewSubscribers')
                            ->label('Subscribing vendors')
                            ->modalHeading(fn (Zone $record): string => "Subscriptions covering {$record->name}")
                            ->modalContent(fn (Zone $record) => view(
                                'filament.zones.subscribers',
                                ['zone' => $record],
                            ))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Close')
                            ->visible(fn (Zone $record): bool => $record->countSubscriptionReferences() > 0)
                    )
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state === 0
                        ? 'Not used'
                        : $state.' '.Str::plural('subscription', $state))
                    // Transitive for parent zones, per SPEC section 8:
                    // deactivating a parent drops every descendant out of
                    // matching, so the count has to reflect the subtree rather
                    // than this row alone.
                    ->tooltip(fn (Zone $record): string => $record->isLeaf()
                        ? 'Subscription selections covering this zone.'
                        : 'Subscription selections covering this zone or any zone beneath it. '
                            .'Deactivating this parent removes all of them from matching.'),

                RecordStatus::column('Draft'),

                TextColumn::make('created_at')
                    ->label('Added on')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Last modified')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters($isSubZone ? [
                SelectFilter::make('parent_id')
                    ->label('Main zone')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
            ] : [])
            ->actions([
                ViewAction::make()
                    ->modalHeading(fn (Zone $record): string => $record->name)
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
                    fn (Zone $record): string => static::statusEffects($record),
                    'Draft',
                ),
                SafeDelete::configure(DeleteAction::make()),
            ])
            ->bulkActions([
                // No bulk delete: each record has its own checks.
            ])
            ->emptyStateHeading($isSubZone ? 'No sub-zones yet' : 'No zones yet')
            ->emptyStateDescription($isSubZone
                ? 'Sub-zones are the areas inside a main zone, such as Gota inside Ahmedabad.'
                : 'Zones decide which vendors a customer sees. Draw a main zone first, then its sub-zones.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Basic details')
                ->collapsible()
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('zone_type')
                        ->label('Type')
                        ->state(fn (Zone $record): string => static::typeLabel($record))
                        ->badge(),
                    TextEntry::make('parent.name')->label('Parent zone')->placeholder('None — main zone'),
                    RecordStatus::entry('Draft'),
                    TextEntry::make('code')->label('Zone code')->placeholder('—'),
                    TextEntry::make('slug'),
                    TextEntry::make('pincode')->placeholder('—'),
                    TextEntry::make('shape_type')
                        ->label('Shape')
                        ->formatStateUsing(fn (Zone $record): string => static::shapeLabel($record)),
                    TextEntry::make('matching')
                        ->label('Customer matching')
                        ->state(fn (Zone $record): string => static::matchingLabel($record))
                        ->columnSpan(2),
                ]),

            InfoSection::make('Sub-zones')
                ->collapsible()
                ->visible(fn (Zone $record): bool => $record->parent_id === null)
                ->schema([
                    TextEntry::make('children_list')
                        ->label('')
                        ->state(fn (Zone $record): array => $record->children()
                            ->orderBy('name')
                            ->get()
                            ->map(fn (Zone $child): string => $child->name.($child->is_active ? '' : ' (draft)'))
                            ->all())
                        ->badge()
                        ->placeholder('No sub-zones — this zone is matched on its own.'),
                ]),

            InfoSection::make('Usage')
                ->collapsible()
                ->schema([
                    TextEntry::make('subscribers')
                        ->label('Vendors covering this zone')
                        ->state(fn (Zone $record): array => $record->subscribingVendors()
                            ->map(fn (object $row): string => sprintf(
                                '%s — %s, %s (%s)',
                                $row->business_name,
                                $row->zone_name,
                                $row->plan_name ?? 'no plan',
                                $row->subscription_status,
                            ))
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('No vendor subscriptions cover this zone.'),
                ]),

            RecordStatus::recordSection('Draft'),
        ]);
    }

    public static function typeLabel(Zone $record): string
    {
        if ($record->parent_id !== null) {
            return 'Sub-zone';
        }

        return $record->children()->exists() ? 'City (has sub-zones)' : 'Standalone zone';
    }

    public static function shapeLabel(Zone $record): string
    {
        if ($record->shape_type === Zone::SHAPE_CIRCLE && $record->radius_km !== null) {
            return 'Circle, '.rtrim(rtrim(number_format($record->radius_km, 3), '0'), '.').' km';
        }

        return 'Polygon';
    }

    /** Whether a customer location inside this boundary is matched here. */
    private static function matchingLabel(Zone $record): string
    {
        if (! $record->is_active) {
            return 'Not matched — this zone is a draft.';
        }

        if ($record->parent_id !== null && ! $record->parent?->is_active) {
            return 'Not matched — its parent zone is a draft.';
        }

        if ($record->children()->exists()) {
            return 'Not matched directly — customers are matched to its sub-zones.';
        }

        return 'Matched — customers inside this boundary see vendors subscribed to it.';
    }

    public static function statusEffects(Zone $record): string
    {
        $count = $record->countSubscriptionReferences();
        $usage = $count === 0
            ? 'No subscriptions cover it.'
            : $count.' subscription '.Str::plural('selection', $count).' cover it — they stay on the subscription either way.';

        $scope = $record->children()->exists()
            ? 'Draft: every sub-zone under it stops being matched too. '
            : 'Draft: customers there are no longer matched here, and it is not offered for selection. ';

        return 'Active: customer locations inside the boundary are matched to vendors subscribed to it. '
            .$scope.$usage;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZones::route('/'),
            'create' => Pages\CreateZone::route('/create'),
            'edit' => Pages\EditZone::route('/{record}/edit'),
        ];
    }
}
