<?php

namespace App\Policies;

use App\Models\User;

/**
 * Settings admin page (SPEC section 5 item 17).
 *
 * No create()/delete() — the key set is fixed (seeded, not
 * admin-defined), so there's nothing to add or remove through the
 * panel, only values to edit.
 */
class SettingPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'settings';
    }
}
