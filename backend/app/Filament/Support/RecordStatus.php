<?php

namespace App\Filament\Support;

use App\Models\AuditLog;
use Closure;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * One shared shape for "is this record live in the apps?" across the master
 * data screens (categories, subcategories, zones, plans), so an admin learns
 * it once.
 *
 * Replaces the one-click ToggleColumn. A toggle on a list row is easy to hit
 * by accident and says nothing about what it does; deactivating a category
 * hides a whole branch of the customer app. Instead the row shows a status
 * badge, and the change goes through a modal that states the effect, asks
 * for a reason, and records who did it and when in audit_logs.
 */
class RecordStatus
{
    public const AUDIT_ACTION = 'status_changed';

    public static function column(string $inactiveLabel = 'Inactive'): TextColumn
    {
        return TextColumn::make('is_active')
            ->label('Status')
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : $inactiveLabel)
            ->color(fn (bool $state): string => $state ? 'success' : 'gray');
    }

    public static function entry(string $inactiveLabel = 'Inactive'): TextEntry
    {
        return TextEntry::make('is_active')
            ->label('Status')
            ->badge()
            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : $inactiveLabel)
            ->color(fn (bool $state): string => $state ? 'success' : 'gray');
    }

    /**
     * All / Active / Inactive tabs with live counts, for a ListRecords page.
     *
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder): Builder)|null  $scope  narrows the counts to
     *         the rows this screen shows, when one table backs two screens
     *         (main zones / sub-zones). The list query is already scoped by
     *         the resource; only the badge counts need telling.
     * @return array<string, Tab>
     */
    public static function tabs(string $model, string $noun, string $inactiveLabel = 'Inactive', ?Closure $scope = null): array
    {
        $count = fn (?bool $active): int => $model::query()
            ->when($scope, $scope)
            ->when($active !== null, fn (Builder $q) => $q->where('is_active', $active))
            ->count();

        return [
            'all' => Tab::make('All '.$noun)
                ->badge($count(null)),

            'active' => Tab::make('Active')
                ->badge($count(true))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),

            'inactive' => Tab::make($inactiveLabel)
                ->badge($count(false))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }

    /**
     * Configures a "Change status" action. Works for both a table row action
     * and a page header action — they share MountableAction.
     *
     * @param  Closure(Model): string  $effects  what Active / Inactive mean
     *                                           for this record, in plain words
     */
    public static function configure(MountableAction $action, Closure $effects, string $inactiveLabel = 'Inactive'): MountableAction
    {
        return $action
            ->label('Change status')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->modalHeading('Change status')
            ->modalDescription(fn (Model $record): string => $effects($record))
            ->modalSubmitActionLabel('Apply')
            ->fillForm(fn (Model $record): array => [
                'is_active' => $record->is_active ? '1' : '0',
            ])
            ->form([
                Select::make('is_active')
                    ->label('Status')
                    ->options(['1' => 'Active', '0' => $inactiveLabel])
                    ->native(false)
                    ->required(),

                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('Why is this being changed?')
                    ->helperText('Recorded against this change, with your name and the time.')
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->authorize(fn (Model $record): bool => Auth::user()?->can('update', $record) ?? false)
            ->action(function (Model $record, array $data) use ($inactiveLabel): void {
                $from = (bool) $record->is_active;
                $to = $data['is_active'] === '1';

                if ($from === $to) {
                    Notification::make()
                        ->title('Status unchanged')
                        ->body('It is already '.($to ? 'Active' : $inactiveLabel).'.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->forceFill(['is_active' => $to])->save();

                // Written here rather than by the RecordsAuditLog events:
                // most of these models do not carry that trait, and the
                // reason only exists in this action.
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => self::AUDIT_ACTION,
                    'auditable_type' => $record->getMorphClass(),
                    'auditable_id' => $record->getKey(),
                    'old_values' => ['is_active' => $from],
                    'new_values' => ['is_active' => $to, 'reason' => $data['reason']],
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::header('User-Agent'),
                ]);

                Notification::make()
                    ->title('Status changed to '.($to ? 'Active' : $inactiveLabel))
                    ->success()
                    ->send();
            });
    }

    /**
     * The closing "Record" section of every view modal: when it was added,
     * last changed, and the status changes with their reasons.
     */
    public static function recordSection(string $inactiveLabel = 'Inactive'): Section
    {
        return Section::make('Record')
            ->collapsible()
            ->collapsed()
            ->columns(2)
            ->schema([
                TextEntry::make('created_at')->label('Added on')->dateTime('d M Y, H:i'),
                TextEntry::make('updated_at')->label('Last modified')->dateTime('d M Y, H:i'),

                TextEntry::make('status_history')
                    ->label('Status changes')
                    ->columnSpanFull()
                    ->state(fn (Model $record): array => self::history($record, $inactiveLabel))
                    ->listWithLineBreaks()
                    ->placeholder('No status changes recorded yet.'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function history(Model $record, string $inactiveLabel = 'Inactive'): array
    {
        return AuditLog::query()
            ->for($record)
            ->where('action', self::AUDIT_ACTION)
            ->with('user')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log): string => sprintf(
                '%s → %s by %s — "%s"',
                $log->created_at?->format('d M Y, H:i'),
                ($log->new_values['is_active'] ?? false) ? 'Active' : $inactiveLabel,
                $log->user?->name ?? 'System',
                $log->new_values['reason'] ?? '',
            ))
            ->all();
    }

    /** Plain-words line for an edit page's subheading. */
    public static function summary(Model $record, string $whenActive, string $whenInactive, string $inactiveLabel = 'Inactive'): string
    {
        return $record->is_active
            ? 'Status: Active — '.$whenActive
            : 'Status: '.$inactiveLabel.' — '.$whenInactive;
    }
}
