<?php

namespace App\Policies;

use App\Models\User;

/**
 * Commission & Payouts permissions (SPEC section 5 item 9).
 *
 * No create/update/delete methods: commissions are only ever created
 * by SubscriptionService as a side effect of a sale, never authored
 * or edited by hand — the only admin action here is markPaid().
 */
class CommissionPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'commissions';
    }

    public function markPaid(User $user): bool
    {
        return $user->hasPermission('commissions.markPaid');
    }
}
