<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Services\TwoFactorService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Enter the code from your app" — on entering /super-admin, and again after
 * RequireSuperAdminTwoFactor::IDLE_MINUTES of no activity there. A recovery
 * code works too (once each).
 */
class TwoFactorChallenge extends Page implements HasForms
{
    use InteractsWithForms;

    /** Wrong codes allowed per minute before a short lock. */
    private const MAX_ATTEMPTS = 5;

    protected static string $view = 'filament.super-admin.two-factor-challenge';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'two-factor';

    protected static ?string $title = 'Two-step login';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        if (RequireSuperAdminTwoFactor::isFresh()) {
            $this->redirect(Dashboard::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Code from your authenticator app, or a recovery code')
                    ->required()
                    ->autocomplete('one-time-code')
                    ->autofocus()
                    ->maxLength(20),
            ])
            ->statePath('data');
    }

    public function verify(TwoFactorService $twoFactor): void
    {
        $key = 'super-admin-2fa|'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'data.code' => 'Too many wrong codes. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $code = (string) ($this->form->getState()['code'] ?? '');

        if (! $twoFactor->verify(auth()->user(), $code)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'data.code' => 'That code is not right, or was already used.',
            ]);
        }

        RateLimiter::clear($key);
        RequireSuperAdminTwoFactor::markVerified();

        $this->redirect(session()->pull(RequireSuperAdminTwoFactor::INTENDED, Dashboard::getUrl()));
    }
}
