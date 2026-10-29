<?php

namespace App\Policies;

use App\Models\User;

/**
 * Subcategory permissions. Subcategories are part of the category tree, so
 * they share the "categories" module grants rather than adding a new set an
 * admin would have to remember to tick.
 *
 * delete only ever reaches an unused subcategory — see SafeDelete.
 */
class SubcategoryPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'categories';
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->hasPermission('categories.delete');
    }
}
