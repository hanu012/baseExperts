<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin → Document Types. Rides on the vendor permissions rather than a
 * module of its own: deciding which documents a vendor must provide is part
 * of verifying vendors. Seeing the list needs vendors.viewAny; changing it
 * needs vendors.verify. No delete — types are switched off.
 */
class DocumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vendors.viewAny');
    }

    public function view(User $user, $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vendors.verify');
    }

    public function update(User $user, $record = null): bool
    {
        return $this->create($user);
    }

    public function reorder(User $user): bool
    {
        return $this->create($user);
    }
}
