<?php

namespace App\Policies;

use App\Models\User;

/**
 * Category module permissions (SPEC section 5.16).
 *
 * delete is a grantable permission, but it only ever reaches a category
 * nothing depends on: the panel's SafeDelete refuses one with subcategories
 * or subscriptions, and PreventsDeletionWhileSubscribed backs that up.
 */
class CategoryPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        // "categories", not the naive Category + s. This must match the
        // prefix used in App\Enums\Permission exactly — a mismatch silently
        // denies everything for this module, since hasPermission() then
        // compares against a string nothing grants.
        return 'categories';
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->hasPermission('categories.delete');
    }
}
