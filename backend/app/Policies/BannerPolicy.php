<?php

namespace App\Policies;

use App\Models\User;

/**
 * Banner Management permissions (SPEC section 5 item 5).
 *
 * Unlike the master-data policies, this one DOES define delete() —
 * banners are genuinely deletable (see Banner's own docblock for why
 * SPEC section 10's no-hard-delete rule doesn't apply here).
 */
class BannerPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'banners';
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->hasPermission('banners.delete');
    }
}
