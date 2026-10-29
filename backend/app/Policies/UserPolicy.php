<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * User management permissions (SPEC sections 5.2 and 5.16).
 *
 * THIS POLICY CARRIES THE PRIVILEGE-ESCALATION GUARD, and it is the reason
 * every other permission means anything.
 *
 * A sub-admin holding `users.update` could otherwise:
 *   - grant themselves `plans.update` by editing their own permissions, or
 *   - create a second super-admin and sign in as it, or
 *   - delete the super-admin who scoped them.
 *
 * So: **only a super-admin may create, edit or delete an admin account**, and
 * only a super-admin may change permissions at all. A sub-admin with
 * `users.update` manages salesmen, vendors and customers — never fellow
 * admins, and never themselves.
 */
class UserPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'users';
    }

    public function update(User $user, $record = null): bool
    {
        if (! $user->hasPermission('users.update')) {
            return false;
        }

        if (! $record instanceof User) {
            return true;
        }

        // Editing any admin — including yourself — is super-admin only.
        // Self-edit is excluded because the permissions field is on this same
        // form: allowing it is exactly the escalation path.
        if ($record->role === UserRole::Admin) {
            return $user->isSuperAdmin();
        }

        return true;
    }

    public function create(User $user): bool
    {
        // The role select offers admin, so creation is gated the same way.
        // A sub-admin who needs to add a salesman goes through a super-admin,
        // which is the intended shape of SPEC section 5.16.
        return $user->hasPermission('users.create') && $user->isSuperAdmin();
    }

    public function delete(User $user, $record = null): bool
    {
        if (! $user->hasPermission('users.delete')) {
            return false;
        }

        if ($record instanceof User) {
            // Never delete an admin unless you are a super-admin, and never
            // delete yourself — an admin locking themselves out has no
            // in-panel recovery.
            if ($record->role === UserRole::Admin && ! $user->isSuperAdmin()) {
                return false;
            }

            if ($record->is($user)) {
                return false;
            }
        }

        return true;
    }

    public function restore(User $user, $record = null): bool
    {
        if (! $user->hasPermission('users.restore')) {
            return false;
        }

        if ($record instanceof User && $record->role === UserRole::Admin) {
            return $user->isSuperAdmin();
        }

        return true;
    }

    /**
     * Whether this user may see and set the permissions field at all.
     *
     * Called by UserResource to hide the field, not merely disable it — a
     * disabled field still round-trips through the request, and Filament
     * would happily save a value injected into the payload.
     */
    public function managePermissions(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
