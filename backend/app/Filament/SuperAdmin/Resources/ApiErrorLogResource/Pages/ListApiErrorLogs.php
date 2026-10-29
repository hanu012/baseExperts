<?php

namespace App\Filament\SuperAdmin\Resources\ApiErrorLogResource\Pages;

use App\Filament\SuperAdmin\Resources\ApiErrorLogResource;
use App\Models\ApiErrorLog;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiErrorLogs extends ListRecords
{
    protected static string $resource = ApiErrorLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearAll')
                ->label('Clear all')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete every logged API error?')
                ->modalDescription('This cannot be undone. New errors keep being recorded while the log is on.')
                ->visible(fn (): bool => auth()->user()?->can('deleteAny', ApiErrorLog::class) ?? false)
                ->action(function (): void {
                    $deleted = ApiErrorLog::query()->delete();

                    Notification::make()->title("Deleted {$deleted} log entries")->success()->send();
                }),
        ];
    }

    /** What is being recorded and the last day's picture, before any filtering. */
    public function getSubheading(): ?string
    {
        $level = Setting::get('api_error_log_level', 'server') === 'all'
            ? 'all failed calls (4xx, 5xx, and replies with "success": false)'
            : 'server errors (5xx) and replies with "success": false';

        $lastDay = ApiErrorLog::query()->where('created_at', '>=', now()->subDay());

        return sprintf(
            'Recording %s. Last 24 hours: %d server errors, %d request errors, %d "success": false replies. Entries are kept %d days.',
            $level,
            (clone $lastDay)->where('status', '>=', 500)->count(),
            (clone $lastDay)->whereBetween('status', [400, 499])->count(),
            (clone $lastDay)->where('status', '<', 400)->count(),
            ApiErrorLog::RETENTION_DAYS,
        );
    }
}
