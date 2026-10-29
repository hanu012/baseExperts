<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Auth;

/**
 * /super-admin → System settings: the technical half of Settings — payment
 * and Maps keys, sign-in / password reset (Firebase), and the API error log
 * switch. The business half stays on SettingsPage in /admin.
 *
 * Same form, save and secret handling as SettingsPage; only the key lists,
 * the sections shown and who may open it differ.
 */
class SystemSettingsPage extends SettingsPage
{
    protected static ?string $navigationLabel = 'System settings';

    protected static ?string $title = 'System settings';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'system-settings';

    protected static function keys(): array
    {
        return self::TECHNICAL_KEYS;
    }

    protected static function secrets(): array
    {
        return self::SECRETS;
    }

    protected static function sections(): array
    {
        return ['payments', 'sign-in', 'error-log', 'maps'];
    }

    public static function canAccess(): bool
    {
        // Super-admins only — the panel already requires it; this keeps the
        // page closed even if it were ever registered somewhere else.
        return Auth::user()?->isSuperAdmin() ?? false;
    }
}
