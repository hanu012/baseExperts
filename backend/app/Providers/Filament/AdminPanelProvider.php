<?php

namespace App\Providers\Filament;

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
use Filament\Widgets;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Service Marketplace')

            // Dark by default, per CLAUDE.md. Left switchable rather than
            // forced so an admin working in daylight is not stuck with it.
            ->defaultThemeMode(ThemeMode::Dark)

            // Single accent colour. Slate as the gray ramp keeps dark surfaces
            // at slate-900 (#0f172a) rather than pure black, and teal stays
            // clear of the red/amber that Filament reserves for destructive
            // and warning states.
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Slate,
            ])
            // Super-admins jump to /super-admin (SuperAdminPanelProvider).
            ->userMenuItems([
                MenuItem::make()
                    ->label('Go to Super Admin')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn (): string => url('/super-admin'))
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
            ])
            // Copy/Share handlers for the temporary-password notification.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.hooks.credential-share'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
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

                // Belt and braces alongside User::canAccessPanel(). Filament
                // calls canAccessPanel() itself; this makes the role rule
                // explicit in the route stack too, using the same middleware
                // the API routes use.
                RolesMiddleware::class.':admin',
            ]);
    }
}
