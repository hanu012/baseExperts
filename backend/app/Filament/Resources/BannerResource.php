<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\TranslatableInput;
use App\Filament\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Banner Management (SPEC section 5 item 5).
 *
 * NOT the same class as App\Http\Resources\BannerResource, which is
 * the public API transformer for the serving endpoint. Same short
 * name, different namespaces — same collision CategoryResource's own
 * docblock warns about.
 *
 * Full CRUD, including delete — deliberately unlike the master-data
 * resources. See Banner's own docblock for why SPEC section 10's
 * no-hard-delete rule doesn't apply to this content type.
 */
class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('target_app')
                ->options([
                    'salesman' => 'Salesman',
                    'vendor' => 'Vendor',
                    'customer' => 'Customer',
                ])
                ->required(),

            TranslatableInput::make(
                'title',
                'Title',
                fn (string $language) => TextInput::make($language)->maxLength(255),
            )->columnSpanFull(),

            // Not a hardcoded enum — SPEC doesn't fix a list of
            // placement slots, and a new one shouldn't need a code
            // change to use.
            TextInput::make('position')
                ->default('home_top')
                ->required()
                ->maxLength(255)
                ->helperText('Placement slot within the target app, e.g. home_top.'),

            FileUpload::make('image_path')
                ->label('Image')
                ->image()
                ->imageEditor()
                ->directory('banners')
                ->required(),

            TextInput::make('link_url')
                ->label('Link URL')
                ->url()
                ->maxLength(255)
                ->helperText('Where tapping the banner goes. Leave blank for a purely informational banner.'),

            DateTimePicker::make('starts_at')
                ->helperText('Leave blank to start showing immediately.'),

            DateTimePicker::make('ends_at')
                ->helperText('Leave blank to show indefinitely.'),

            Toggle::make('is_active')
                ->default(true),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('')
                    ->getStateUsing(fn (Banner $record): ?string => $record->fileUrl()),

                TextColumn::make('title')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('target_app')
                    ->badge(),

                TextColumn::make('position'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                // Read-only — a counter the click endpoint owns, not
                // something an admin edits. Not present in form(), so
                // it's outside the form's own state entirely; a
                // crafted submission couldn't set it even if it tried.
                TextColumn::make('click_count')
                    ->label('Clicks')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->placeholder('Immediately'),

                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime()
                    ->placeholder('Indefinitely'),
            ])
            ->filters([
                SelectFilter::make('target_app')
                    ->options([
                        'salesman' => 'Salesman',
                        'vendor' => 'Vendor',
                        'customer' => 'Customer',
                    ]),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No banners yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
