<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\TranslatableInput;
use App\Filament\Resources\SubcategoryResource\Pages;
use App\Filament\Support\RecordStatus;
use App\Models\Category;
use App\Models\Subcategory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use App\Filament\Support\SafeDelete;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Subcategories as their own screen (SPEC section 5.7), separate from the
 * main categories — each one picks its parent from the "Category" field.
 *
 * This is the level vendor matching actually happens at (SPEC section 4.4).
 *
 * DELETE ONLY WHEN UNUSED (SafeDelete): refused while any subscription
 * includes it (subscription_items.item_id has no foreign key, SPEC section
 * 10) or any lead points at it (ON DELETE RESTRICT). Otherwise deactivate.
 */
class SubcategoryResource extends Resource
{
    protected static ?string $model = Subcategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make()
                ->columns(2)
                ->schema([
                    Grid::make(1)
                        ->columnSpan(1)
                        ->schema([
                            TranslatableInput::make('name', 'Subcategory name', function (string $language) {
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
                            }, required: true),

                            Select::make('category_id')
                                ->label('Category')
                                ->relationship('category', 'slug')
                                ->getOptionLabelFromRecordUsing(fn (Category $record): string => $record->name)
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->helperText('The main category this service sits under.'),

                            TextInput::make('slug')
                                ->required()
                                ->maxLength(255)
                                // Unique within the chosen category, matching
                                // the composite unique index on the table.
                                ->unique(
                                    modifyRuleUsing: fn ($rule, Get $get) => $rule->where('category_id', $get('category_id')),
                                    ignoreRecord: true,
                                )
                                ->helperText('URL-friendly name, unique within its category.'),

                            FileUpload::make('icon')
                                ->image()
                                ->imageEditor()
                                ->directory('subcategory-icons')
                                ->maxSize(1024),
                        ]),

                    Grid::make(1)
                        ->columnSpan(1)
                        ->schema([
                            TextInput::make('sort_order')
                                ->label('Display order')
                                ->numeric()
                                ->minValue(0)
                                ->default(0),

                            Select::make('service_type')
                                ->label('Service type')
                                ->options(static::serviceTypes())
                                ->native(false)
                                ->helperText("Backs the filter chips on the customer app's subcategory screen. Leave blank if none fit."),

                            Toggle::make('is_popular')
                                ->label('Popular')
                                ->helperText('Shows a "Popular" badge and surfaces this service first on the customer app.'),

                            // Starting status only; afterwards it goes through
                            // "Change status". Hidden on edit, not disabled.
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true)
                                ->hiddenOn('edit')
                                ->helperText('Inactive subcategories are hidden from the apps but keep their existing subscriptions.'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordUrl(null)
            ->recordAction('view')
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->circular(),

                TextColumn::make('name')
                    ->label('Subcategory name')
                    ->searchable(query: \App\Support\TranslatableColumn::search('name'))
                    ->sortable(query: \App\Support\TranslatableColumn::sort('name'))
                    ->weight('bold'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('slug')
                    ->searchable()
                    ->color('gray'),

                TextColumn::make('service_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => static::serviceTypes()[$state] ?? '—')
                    ->placeholder('—'),

                TextColumn::make('sort_order')
                    ->label('Display order')
                    ->sortable(),

                IconColumn::make('is_popular')
                    ->label('Popular')
                    ->boolean(),

                TextColumn::make('in_use')
                    ->label('In use')
                    ->state(fn (Subcategory $record): int => $record->countSubscriptionReferences())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state === 0
                        ? 'Not used'
                        : $state.' '.Str::plural('subscription', $state))
                    ->toggleable(),

                RecordStatus::column(),

                TextColumn::make('updated_at')
                    ->label('Last modified')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'slug')
                    ->getOptionLabelFromRecordUsing(fn (Category $record): string => $record->name)
                    ->preload(),
            ])
            ->actions([
                static::viewAction(),
                EditAction::make(),
                RecordStatus::configure(TableAction::make('changeStatus'), fn (Subcategory $record): string => static::statusEffects($record)),
                SafeDelete::configure(DeleteAction::make()),
            ])
            ->bulkActions([
                // No bulk delete: each record has its own checks.
            ])
            ->emptyStateHeading('No subcategories yet')
            ->emptyStateDescription('Vendors are matched to customers at this level, so every category needs at least one.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Basic details')
                ->collapsible()
                ->columns(4)
                ->schema([
                    TextEntry::make('name_en')
                        ->label('Name (English)')
                        ->state(fn (Subcategory $record): ?string => $record->getTranslation('name', 'en', false) ?: null)
                        ->weight('bold'),
                    TextEntry::make('name_hi')
                        ->label('Name (Hindi)')
                        ->state(fn (Subcategory $record): ?string => $record->getTranslation('name', 'hi', false) ?: null)
                        ->placeholder('—'),
                    TextEntry::make('name_gu')
                        ->label('Name (Gujarati)')
                        ->state(fn (Subcategory $record): ?string => $record->getTranslation('name', 'gu', false) ?: null)
                        ->placeholder('—'),
                    RecordStatus::entry(),
                    TextEntry::make('category.name')->label('Category'),
                    TextEntry::make('slug'),
                    TextEntry::make('service_type')
                        ->label('Service type')
                        ->formatStateUsing(fn (?string $state): string => static::serviceTypes()[$state] ?? '—')
                        ->placeholder('—'),
                    IconEntry::make('is_popular')->label('Popular')->boolean(),
                    TextEntry::make('sort_order')->label('Display order'),
                    ImageEntry::make('icon')->circular()->placeholder('No icon'),
                ]),

            Section::make('Usage')
                ->collapsible()
                ->schema([
                    TextEntry::make('in_use')
                        ->label('Subscription selections')
                        ->state(fn (Subcategory $record): string => static::usageLabel($record->countSubscriptionReferences()))
                        ->helperText('Vendor subscriptions that include this service.'),
                ]),

            RecordStatus::recordSection(),
        ]);
    }

    /** View popup with an Edit button in its footer, next to Close. */
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->modalHeading(fn (Subcategory $record): string => $record->name)
            ->extraModalFooterActions(fn (ViewAction $action): array => [
                $action->makeModalAction('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->url(static::getUrl('edit', ['record' => $action->getRecord()])),
            ]);
    }

    /** @return array<string, string> */
    public static function serviceTypes(): array
    {
        return [
            'installation' => 'Installation',
            'repair' => 'Repair',
            'maintenance' => 'Maintenance',
        ];
    }

    public static function statusEffects(Subcategory $record): string
    {
        return 'Active: customers can find vendors for it, and salesmen and vendors can select it. '
            .'Inactive: hidden from the apps and from vendor detail pages. '
            .static::usageLabel($record->countSubscriptionReferences())
            .' — existing subscriptions keep running either way.';
    }

    private static function usageLabel(int $count): string
    {
        return $count === 0 ? 'No subscriptions include it' : $count.' '.Str::plural('subscription', $count).' include it';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubcategories::route('/'),
            'create' => Pages\CreateSubcategory::route('/create'),
            'edit' => Pages\EditSubcategory::route('/{record}/edit'),
        ];
    }
}
