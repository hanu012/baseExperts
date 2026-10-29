<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentTypeResource\Pages;
use App\Models\DocumentType;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Admin → Document Types: the KYC documents vendors are asked for, and
 * which are compulsory before an account can be approved (VendorKycService).
 *
 * No delete, by design: a type vendors have uploaded is referenced by
 * vendor_documents (ON DELETE RESTRICT). Switch it off instead — it then
 * leaves every vendor's list and the approval rule, keeping the uploads.
 *
 * Changing "compulsory" takes effect at once for everyone not yet
 * approved; vendors already approved stay approved.
 */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Document Types';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Document')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, Get $get, ?DocumentType $record) {
                            if ($record === null && blank($get('code'))) {
                                $set('code', Str::slug((string) $state, '_'));
                            }
                        }),

                    TextInput::make('code')
                        ->required()
                        ->maxLength(50)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText('Short fixed id, e.g. driving_license. Not shown to vendors.'),

                    TextInput::make('description')
                        ->maxLength(500)
                        ->helperText('Shown to vendors above the upload, e.g. "Front and back, first 8 digits masked".')
                        ->columnSpanFull(),

                    Toggle::make('is_compulsory')
                        ->label('Compulsory')
                        ->helperText('Must be Verified before the vendor account can be approved.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Off: hidden from vendors and ignored for approval. Uploads are kept.'),

                    TextInput::make('sort_order')->numeric()->default(0)->minValue(0),
                ]),

            Section::make('What the upload needs')
                ->columns(2)
                ->schema([
                    Toggle::make('has_back')->label('Back photo')->helperText('Ask for the back as well as the front.'),

                    Toggle::make('has_expiry')->label('Expiry date')->helperText('Expired documents are flagged daily and the vendor is told.'),

                    Toggle::make('has_number')->label('ID number')->default(true)->live(),

                    Toggle::make('mask_number')
                        ->label('Last 4 digits only')
                        ->helperText('For Aadhaar (UIDAI rules): only the last 4 digits are asked for and kept.')
                        ->visible(fn (Get $get): bool => (bool) $get('has_number'))
                        ->live(),

                    TextInput::make('number_label')
                        ->label('Number label')
                        ->placeholder('e.g. PAN number')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('has_number')),

                    TextInput::make('number_hint')
                        ->label('Example')
                        ->placeholder('e.g. ABCDE1234F')
                        ->maxLength(100)
                        ->visible(fn (Get $get): bool => (bool) $get('has_number')),

                    TextInput::make('number_pattern')
                        ->label('Format (regular expression)')
                        ->placeholder('[A-Z]{5}[0-9]{4}[A-Z]')
                        ->helperText('Optional. Checked case-insensitively against the whole number.')
                        ->maxLength(255)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (filled($value) && @preg_match('/^'.$value.'$/i', '') === false) {
                                $fail('This is not a valid regular expression.');
                            }
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('has_number') && ! $get('mask_number'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (DocumentType $record): ?string => $record->description),
                ToggleColumn::make('is_compulsory')->label('Compulsory'),
                IconColumn::make('has_number')->label('Number')->boolean(),
                IconColumn::make('has_back')->label('Back')->boolean(),
                IconColumn::make('has_expiry')->label('Expiry')->boolean(),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('vendor_documents_count')->counts('vendorDocuments')->label('Vendors')->sortable(),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTypes::route('/'),
            'create' => Pages\CreateDocumentType::route('/create'),
            'edit' => Pages\EditDocumentType::route('/{record}/edit'),
        ];
    }
}
