<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\User;
use App\Support\Totp;
use Filament\Facades\Filament;

/**
 * For tests of the /super-admin panel: a super-admin whose two-step login
 * is set up and already passed in this session, so a page request reaches
 * the page instead of the setup / code screen. Livewire tests also need the
 * panel set as current, since two panels now exist.
 */
trait ActsAsSuperAdmin
{
    protected function superAdminWithTwoFactor(array $attributes = []): User
    {
        $user = User::factory()->role(UserRole::Admin)->create($attributes);

        $user->forceFill([
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
        ])->save();

        return $user;
    }

    /** Signs in as a super-admin who has passed two-step login. */
    protected function actingAsVerifiedSuperAdmin(?User $user = null): User
    {
        $user ??= $this->superAdminWithTwoFactor();

        $this->actingAs($user)->withSession([
            RequireSuperAdminTwoFactor::VERIFIED_AT => now()->getTimestamp(),
            RequireSuperAdminTwoFactor::LAST_SEEN => now()->getTimestamp(),
        ]);

        $this->useSuperAdminPanel();

        return $user;
    }

    protected function useSuperAdminPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));
    }
}
