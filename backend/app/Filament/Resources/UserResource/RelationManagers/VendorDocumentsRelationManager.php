<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\UserRole;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Services\VendorKycService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The vendor's KYC documents — the "Documents" tab on a vendor user's page
 * (decided 2026-10-28; rules in VendorKycService).
 *
 * One row per active document type, uploaded or not ("Pending upload"),
 * in the order set on Admin → Document Types. The admin can upload or
 * replace any document, start a review, verify it, or send it back with a
 * reason the vendor sees in the app. The account's Approve stays greyed
 * out until every compulsory type here is Verified.
 *
 * Viewing needs vendors.viewAny; every change needs vendors.verify.
 */
class VendorDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendorDocuments';

    protected static ?string $title = 'Documents';

    protected static ?string $icon = 'heroicon-o-document-check';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User
            && $ownerRecord->role === UserRole::Vendor
            && $ownerRecord->vendor !== null
            && (Auth::user()?->can('viewAny', Vendor::class) ?? false);
    }

    private function vendor(): ?Vendor
    {
        return $this->getOwnerRecord()->vendor;
    }

    private static function canReview(): bool
    {
        return Auth::user()?->can('verify', Vendor::class) ?? false;
    }

    public function table(Table $table): Table
    {
        $kyc = app(VendorKycService::class);
        $vendor = $this->vendor();

        // Every active type gets its row (a type added since) before the
        // table is drawn.
        if ($vendor !== null) {
            $kyc->syncTypes($vendor);
        }

        $missing = $vendor !== null ? $kyc->missingCompulsory($vendor) : [];

        return $table
            ->heading('KYC documents')
            ->description($missing === []
                ? 'All compulsory documents are verified — the account can be approved.'
                : 'Compulsory, not yet verified: '.implode(', ', $missing).'. The account cannot be approved until each is uploaded and verified.')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('documentType')
                ->whereHas('documentType', fn (Builder $q) => $q->where('is_active', true))
                ->orderBy(
                    DocumentType::query()->select('sort_order')
                        ->whereColumn('document_types.id', 'vendor_documents.document_type_id')
                ))
            ->paginated(false)
            ->columns([
                TextColumn::make('index')->label('#')->rowIndex(),

                TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->weight('bold')
                    ->description(fn (VendorDocument $record): ?string => $record->documentType->is_compulsory ? 'Compulsory' : null),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VendorDocument::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        VendorDocument::VERIFIED => 'success',
                        VendorDocument::REUPLOAD, VendorDocument::EXPIRED => 'danger',
                        VendorDocument::IN_VERIFICATION => 'warning',
                        VendorDocument::UPLOADED => 'info',
                        default => 'gray',
                    })
                    ->description(fn (VendorDocument $record): ?string => $record->status === VendorDocument::REUPLOAD
                        ? $record->review_note
                        : null),

                TextColumn::make('number')->label('ID / number')->placeholder('—'),

                TextColumn::make('expiry_date')->label('Expiry date')->date('d/m/Y')->placeholder('—'),

                TextColumn::make('uploaded_at')->label('Uploaded')->since()->placeholder('—'),
            ])
            ->actions([
                Action::make('front')
                    ->label('Front')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (VendorDocument $record): ?string => $record->fileUrl('front'), shouldOpenInNewTab: true)
                    ->visible(fn (VendorDocument $record): bool => $record->front_path !== null),

                Action::make('back')
                    ->label('Back')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (VendorDocument $record): ?string => $record->fileUrl('back'), shouldOpenInNewTab: true)
                    ->visible(fn (VendorDocument $record): bool => $record->back_path !== null),

                static::uploadAction(),

                ActionGroup::make([
                    Action::make('startReview')
                        ->label('Start review')
                        ->icon('heroicon-o-magnifying-glass')
                        ->visible(fn (VendorDocument $record): bool => static::canReview() && $record->status === VendorDocument::UPLOADED)
                        ->action(fn (VendorDocument $record) => app(VendorKycService::class)->startReview($record, Auth::user())),

                    Action::make('verify')
                        ->label('Verify')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(fn (VendorDocument $record): string => "Verify {$record->documentType->name}?")
                        ->modalDescription('Check the photos against the number and expiry date before verifying.')
                        ->visible(fn (VendorDocument $record): bool => static::canReview()
                            && in_array($record->status, [VendorDocument::UPLOADED, VendorDocument::IN_VERIFICATION], true))
                        ->action(function (VendorDocument $record) {
                            app(VendorKycService::class)->verify($record, Auth::user());
                            Notification::make()->title($record->documentType->name.' verified')->success()->send();
                        }),

                    Action::make('requestReupload')
                        ->label('Ask to re-upload')
                        ->icon('heroicon-o-arrow-path')
                        ->color('danger')
                        ->visible(fn (VendorDocument $record): bool => static::canReview()
                            && $record->status !== VendorDocument::PENDING_UPLOAD
                            && $record->status !== VendorDocument::REUPLOAD)
                        ->form([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->helperText('Shown to the vendor in the app, and sent as a notification.')
                                ->required()
                                ->maxLength(1000),
                        ])
                        ->action(function (VendorDocument $record, array $data) {
                            app(VendorKycService::class)->requestReupload($record, Auth::user(), $data['reason']);
                            Notification::make()->title('Re-upload requested')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No document types')
            ->emptyStateDescription('Add them on Admin → Document Types.');
    }

    /** Upload or replace — the fields depend on the document type. */
    private static function uploadAction(): Action
    {
        $files = fn (string $label, bool $required) => FileUpload::make($label)
            ->disk(VendorDocument::storageDisk())
            ->directory(fn (VendorDocument $record): string => 'vendor-kyc/'.$record->vendor_id)
            ->visibility('private')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->maxSize(10240)
            ->helperText('JPG, PNG, WEBP or PDF · max 10 MB')
            ->required($required);

        return Action::make('upload')
            ->label(fn (VendorDocument $record): string => $record->front_path ? 'Replace' : 'Upload document')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->button()
            ->visible(fn (): bool => static::canReview())
            ->modalHeading(fn (VendorDocument $record): string => 'Upload document: '.$record->documentType->name)
            ->modalDescription(fn (VendorDocument $record): ?string => $record->documentType->description)
            ->modalSubmitActionLabel('Upload')
            ->form(fn (VendorDocument $record): array => array_values(array_filter([
                $files('front_path', true)->label($record->documentType->has_back ? 'Front photo' : 'Photo or PDF'),

                $record->documentType->has_back
                    ? $files('back_path', true)->label('Back photo')
                    : null,

                $record->documentType->has_number
                    ? TextInput::make('number')
                        ->label($record->documentType->number_label ?: 'Number')
                        ->placeholder($record->documentType->number_hint)
                        ->helperText($record->documentType->mask_number ? 'Only the last 4 digits are kept.' : null)
                        ->rules(VendorKycService::numberRules($record->documentType))
                        ->required()
                    : null,

                $record->documentType->has_expiry
                    ? DatePicker::make('expiry_date')
                        ->label('Expiry date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->after('today')
                        ->required()
                    : null,
            ])))
            ->action(function (VendorDocument $record, array $data) {
                app(VendorKycService::class)->recordUpload(
                    $record->vendor,
                    $record->documentType,
                    $data,
                    Auth::user(),
                    byAdmin: true,
                );

                Notification::make()->title($record->documentType->name.' uploaded')->body('Verify it when you have checked it.')->success()->send();
            });
    }
}
