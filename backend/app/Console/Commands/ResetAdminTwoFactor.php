<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AdminAccountService;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/**
 * Last resort for a super-admin locked out of /super-admin: phone lost and
 * no recovery code left, and no other super-admin to use "Reset two-step
 * login" on the Admins screen. Needs server (shell) access, which is the
 * point — whoever runs it already controls the app.
 */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:reset-two-factor {email : The admin\'s sign-in email}';

    protected $description = 'Turn off an admin\'s two-step login so they can set it up again.';

    public function handle(TwoFactorService $twoFactor, AdminAccountService $accounts): int
    {
        $user = User::query()
            ->where('role', UserRole::Admin)
            ->where('email', $this->argument('email'))
            ->first();

        if ($user === null) {
            $this->error('No admin account with that email.');

            return self::FAILURE;
        }

        $twoFactor->reset($user);
        $accounts->signOutEverywhere($user);

        $user->writeAuditLog('two_factor_reset', ['two_factor' => 'on'], ['two_factor' => 'off', 'by' => 'console']);

        $this->info("Two-step login reset for {$user->email}. They set it up again the next time they open /super-admin.");

        return self::SUCCESS;
    }
}
