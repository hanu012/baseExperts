<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Http\Middleware\RolesMiddleware;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * /super-admin — the system panel, for super-admins only
 * (User::canAccessPanel). Daily work stays in /admin (AdminPanelProvider).
 *
 * Same app, database and login as /admin: one sign-in reaches both, and the
 * user menu switches between them. On top of the password this panel
 * requires two-step login (RequireSuperAdminTwoFactor).
 *
 * Blue, not teal, so nobody mistakes which panel they are in; still clear of
 * the red/amber Filament reserves for destructive and warning states.
 */
class SuperAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('super-admin')
            ->path('super-admin')
            ->login()
            ->brandName('Service Marketplace · Super Admin')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Go to Admin panel')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->url(fn (): string => url('/admin')),
            ])
            // Copy/Share handlers for the temporary-password notification a
            // new admin account shows (same script as /admin).
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.hooks.credential-share'))
            ->discoverResources(in: app_path('Filament/SuperAdmin/Resources'), for: 'App\\Filament\\SuperAdmin\\Resources')
            ->discoverPages(in: app_path('Filament/SuperAdmin/Pages'), for: 'App\\Filament\\SuperAdmin\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/SuperAdmin/Widgets'), for: 'App\\Filament\\SuperAdmin\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Same belt-and-braces role check as /admin; canAccessPanel()
                // then narrows it to super-admins.
                RolesMiddleware::class.':admin',
                RequireSuperAdminTwoFactor::class,
            ]);
    }
}
