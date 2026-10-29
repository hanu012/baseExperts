<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules behind Super Admin → Admins that must hold however the screen
 * changes:
 *
 *  - There is always at least one super-admin. The last one cannot be
 *    downgraded, deleted, or lose access — with none left, nobody could
 *    ever manage admins, settings or the error log again.
 *  - A super-admin cannot change or delete their own account here (the
 *    screen hides those controls for their own row; this re-checks).
 */
class AdminAccountService
{
    /** Ready-made permission sets, so a new sub-admin is a click, not 30 ticks. */
    public const TEMPLATES = [
        'support' => [
            'label' => 'Support — users, reviews, reports',
            'permissions' => [
                'users.viewAny', 'users.update',
                'vendors.viewAny',
                'reviews.viewAny', 'reviews.hide',
                'leads.viewAny',
            ],
        ],
        'verification' => [
            'label' => 'Verification team — vendors and media',
            'permissions' => [
                'users.viewAny',
                'vendors.viewAny', 'vendors.verify',
                'media.viewAny', 'media.moderate',
            ],
        ],
        'accounts' => [
            'label' => 'Accounts — payments and commissions',
            'permissions' => [
                'payments.viewAny', 'payments.verify',
                'commissions.viewAny', 'commissions.markPaid',
                'reports.viewAny',
            ],
        ],
        'catalogue' => [
            'label' => 'Catalogue — categories, zones, plans, content',
            'permissions' => [
                'categories.viewAny', 'categories.create', 'categories.update',
                'zones.viewAny', 'zones.create', 'zones.update',
                'plans.viewAny', 'plans.create', 'plans.update',
                'banners.viewAny', 'banners.create', 'banners.update',
                'pages.viewAny', 'pages.create', 'pages.update',
            ],
        ],
    ];

    /** Live (not deleted) super-admins. Few rows, so filtered in PHP. */
    public function superAdminCount(): int
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->get(['id', 'role', 'permissions'])
            ->filter(fn (User $user) => $user->isSuperAdmin())
            ->count();
    }

    public function isLastSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() && $this->superAdminCount() <= 1;
    }

    /** Why this super-admin may not delete $target, or null when they may. */
    public function deletionBlocker(User $actor, User $target): ?string
    {
        if ($actor->is($target)) {
            return 'You cannot delete your own account.';
        }

        if ($this->isLastSuperAdmin($target)) {
            return 'This is the last super-admin. Make another admin a super-admin first.';
        }

        return null;
    }

    /**
     * The permissions to store for an admin: the wildcard for a
     * super-admin, otherwise the ticked ones that really exist.
     *
     * @param  array<int, string>|null  $ticked
     * @return array<int, string>
     */
    public function permissionsFor(bool $superAdmin, ?array $ticked): array
    {
        if ($superAdmin) {
            return [Permission::WILDCARD];
        }

        return array_values(array_intersect($ticked ?? [], Permission::values()));
    }

    /**
     * Ends every session of this account: app tokens, panel sessions, and
     * "remember me". They have to sign in again everywhere.
     */
    public function signOutEverywhere(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
