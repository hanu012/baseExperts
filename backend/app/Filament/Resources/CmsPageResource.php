<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\TranslatableInput;
use App\Filament\Resources\CmsPageResource\Pages;
use App\Models\CmsPage;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * CMS Pages (SPEC section 5 item 13) — Terms, Privacy Policy, Refund
 * Policy, FAQ, About, served publicly at /pages/{slug}.
 *
 * NO DELETE ACTION, deliberately — see CmsPage's own docblock. Unlike
 * Category/Zone this isn't SPEC section 10's referential-integrity
 * rule (nothing references a cms_page by id); it's that `slug` is a
 * fixed URL an app store listing may already reference. Unpublishing
 * is the safe takedown path.
 */
class CmsPageResource extends Resource
{
    protected static ?string $model = CmsPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('The public URL: /pages/{slug}. Changing it breaks any link already published (including app store listing metadata).'),

            TranslatableInput::make(
                'title',
                'Title',
                fn (string $language) => TextInput::make($language)->maxLength(255),
                required: true,
            )->columnSpanFull(),

            Select::make('target_app')
                ->label('Target app')
                ->options([
                    'salesman' => 'Salesman',
                    'vendor' => 'Vendor',
                    'customer' => 'Customer',
                ])
                ->placeholder('All apps')
                ->helperText('Leave blank to show for every app.'),

            // One per line rather than side by side: a page body needs the
            // full width to be editable at all.
            TranslatableInput::make(
                'body',
                'Body',
                fn (string $language) => MarkdownEditor::make($language)->columnSpanFull(),
                columns: 1,
            )->columnSpanFull(),

            Toggle::make('is_published')
                ->helperText('Only published pages are reachable at their public URL.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->searchable(),

                TextColumn::make('target_app')
                    ->badge()
                    ->placeholder('All apps'),

                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),

                TextColumn::make('updatedBy.name')
                    ->label('Last updated by')
                    ->placeholder('—'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published')
                    ->placeholder('All')
                    ->trueLabel('Published only')
                    ->falseLabel('Unpublished only'),

                SelectFilter::make('target_app')
                    ->options([
                        'salesman' => 'Salesman',
                        'vendor' => 'Vendor',
                        'customer' => 'Customer',
                    ]),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                // No DeleteAction — see the class docblock.
            ])
            ->bulkActions([
                // No DeleteBulkAction either.
            ])
            ->emptyStateHeading('No CMS pages yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCmsPages::route('/'),
            'create' => Pages\CreateCmsPage::route('/create'),
            'edit' => Pages\EditCmsPage::route('/{record}/edit'),
        ];
    }
}
