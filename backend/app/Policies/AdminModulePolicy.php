<?php

namespace App\Policies;

use App\Models\User;

/**
 * Shared base for the admin panel's model policies (SPEC section 5.16).
 *
 * Each subclass names its module; the ability string is then
 * `{module}.{action}`, where action is the policy method name. That
 * correspondence is deliberate — it means a policy method can resolve its own
 * permission without a lookup table, and a permission cannot exist in the
 * enum but be checked nowhere.
 *
 * FAILS CLOSED by construction: every method funnels through
 * User::hasPermission(), which returns false for an empty or null permissions
 * array and for any non-admin role.
 *
 * NOTE there is no `delete` here. A policy that allows deleting declares it
 * itself: categories, subcategories and zones do (unused records only — see
 * App\Filament\Support\SafeDelete); plans do not, being blocked by an ON
 * DELETE RESTRICT foreign key. Without the method, Filament denies it.
 */
abstract class AdminModulePolicy
{
    /**
     * The permission prefix, e.g. 'plans'.
     */
    abstract protected function module(): string;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->module().'.viewAny');
    }

    public function view(User $user, $record = null): bool
    {
        // Seeing one record requires the same grant as seeing the list: there
        // is no meaningful "can open this row but not browse" state here.
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->module().'.create');
    }

    public function update(User $user, $record = null): bool
    {
        return $user->hasPermission($this->module().'.update');
    }

    /**
     * Reordering rewrites sort_order on every row it touches, so it is an
     * edit, not a view.
     */
    public function reorder(User $user): bool
    {
        return $this->update($user);
    }
}
