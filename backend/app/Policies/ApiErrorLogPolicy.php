<?php

namespace App\Policies;

use App\Models\User;

/**
 * Super Admin → API error log. Super-admins only: error details can include
 * request data and stack traces. It lives in the /super-admin panel, so the
 * old grantable `logs.*` permissions are gone — there is nothing for a
 * sub-admin to be granted.
 *
 * View and delete only — rows are written by the LogApiErrors middleware,
 * never created or edited in the panel.
 */
class ApiErrorLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, $record = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $record = null): bool
    {
        return false;
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
