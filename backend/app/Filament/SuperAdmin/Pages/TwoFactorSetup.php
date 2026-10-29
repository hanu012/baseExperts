<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * First visit to /super-admin: two-step login is required, so a
 * super-admin without it lands here (RequireSuperAdminTwoFactor) and cannot
 * reach any other page until it is on.
 *
 * Add the key to an authenticator app → type the code it shows → keep the
 * recovery codes. No QR code: there is no QR library in the project, so the
 * key is typed in ("Enter a setup key"), or on a phone the otpauth link
 * opens the app directly.
 */
class TwoFactorSetup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.super-admin.two-factor-setup';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'two-factor-setup';

    protected static ?string $title = 'Set up two-step login';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * The pending key — shown on the page, which is the point: the owner
     * types it into their app. Never shown again once setup is finished.
     */
    public string $secret = '';

    /** @var array<int, string> shown once, right after setup */
    public array $recoveryCodes = [];

    public function mount(TwoFactorService $twoFactor): void
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->hasTwoFactorEnabled()) {
            $this->redirect(Dashboard::getUrl());

            return;
        }

        $this->secret = filled($user->two_factor_secret) ? $user->two_factor_secret : $twoFactor->begin($user);
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('6-digit code from the app')
                    ->required()
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->maxLength(10),
            ])
            ->statePath('data');
    }

    public function confirm(TwoFactorService $twoFactor): void
    {
        $code = (string) ($this->form->getState()['code'] ?? '');
        $codes = $twoFactor->confirm(auth()->user(), $code);

        if ($codes === null) {
            throw ValidationException::withMessages([
                'data.code' => 'That code is not right. Check the app shows this account, and that your phone\'s clock is set automatically.',
            ]);
        }

        RequireSuperAdminTwoFactor::markVerified();

        $this->recoveryCodes = $codes;
        $this->secret = '';
    }

    public function finish(): void
    {
        $this->redirect(Dashboard::getUrl());
    }

    public function formattedSecret(): string
    {
        return Totp::formatForDisplay($this->secret);
    }

    public function otpauthUri(): string
    {
        return Totp::uri($this->secret, (string) auth()->user()->email, TwoFactorService::ISSUER);
    }
}
