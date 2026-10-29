<?php

namespace App\Policies;

use App\Models\User;

/**
 * Zone module permissions (SPEC section 5.16). Covers main zones and
 * sub-zones alike — they are the same table.
 *
 * delete is a grantable permission, but it only ever reaches a zone nothing
 * depends on: the panel's SafeDelete refuses one with sub-zones or
 * subscriptions, and PreventsDeletionWhileSubscribed backs that up.
 */
class ZonePolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'zones';
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->hasPermission('zones.delete');
    }
}
