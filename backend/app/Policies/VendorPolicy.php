<?php

namespace App\Policies;

use App\Models\User;

/**
 * Vendor verification queue permissions (SPEC section 5.8/5.16).
 *
 * No create/update/delete methods: this queue only transitions status via
 * verify(), it never creates, edits fields on, or deletes a vendor.
 */
class VendorPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'vendors';
    }

    public function verify(User $user): bool
    {
        return $user->hasPermission('vendors.verify');
    }
}
