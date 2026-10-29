<?php

namespace App\Policies;

use App\Models\User;

/**
 * Super Admin → Activity log. Read-only for super-admins: an audit trail
 * that can be edited or deleted is not one (see AuditLog).
 */
class AuditLogPolicy
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
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
