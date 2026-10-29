<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Support\SystemHealth;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** Super Admin → System health: is everything this app needs actually running? */
class SystemHealthPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'system-health';

    protected static string $view = 'filament.super-admin.system-health';

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /** @return array<int, array{label: string, status: string, detail: string}> */
    public function getChecks(): array
    {
        return app(SystemHealth::class)->checks();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Check again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => null),
        ];
    }
}
