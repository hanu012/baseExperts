<?php

namespace Database\Seeders;

use App\Enums\ApprovalStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first admin account, since nothing else can create one yet —
 * self-registration rejects the admin role by design (SPEC section 1), and
 * Filament's make:filament-user would leave `role` at its 'customer' default,
 * producing a user who cannot get into the panel.
 *
 *     php artisan db:seed --class=AdminUserSeeder
 *
 * Credentials come from env so real ones never land in version control.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@servicemarketplace.local');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'role' => UserRole::Admin,

                // The wildcard, explicitly. Permissions fail closed
                // (SPEC section 5.16), so an admin seeded without this can
                // reach the panel shell but no resource inside it — which
                // looks like a broken install rather than a permissions
                // problem. The migration backfills pre-existing admins, but a
                // fresh `migrate:fresh --seed` creates this row *after* that
                // migration has run, so the backfill never sees it.
                'permissions' => [Permission::WILDCARD],
            ]
        );

        // forceFill, not part of the array above: none of these three are in
        // User::$fillable, and mass assignment drops unfillable keys in
        // silence rather than erroring. Passed to updateOrCreate they would
        // simply never be written, leaving the bootstrap admin Pending — and
        // so locked out of the only panel that can approve anything.
        $user->forceFill([
            // Admins are created deliberately, not self-registered, so there
            // is no address to confirm.
            'email_verified_at' => $user->email_verified_at ?? now(),

            // Approved outright (SPEC section 3.1). There is nobody above
            // this account to approve it.
            'approval_status' => ApprovalStatus::Approved,
            'approval_decided_at' => $user->approval_decided_at ?? now(),
        ])->save();

        $this->command?->info("Admin ready: {$email}");
    }
}
