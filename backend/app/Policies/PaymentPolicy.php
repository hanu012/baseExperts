<?php

namespace App\Policies;

use App\Models\User;

/**
 * Cash-collection reconciliation permissions (SPEC section 5 item 9 /
 * section 5.9). No create/update/delete methods: this queue only
 * transitions admin_verified_at via verify(), it never creates, edits,
 * or deletes a payment.
 */
class PaymentPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'payments';
    }

    public function verify(User $user): bool
    {
        return $user->hasPermission('payments.verify');
    }
}
