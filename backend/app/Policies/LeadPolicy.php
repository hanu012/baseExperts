<?php

namespace App\Policies;

/**
 * Leads Analytics page permissions (SPEC section 5 item 11). viewAny/
 * view only — inherited from AdminModulePolicy. There is no create/
 * update/delete: leads are an append-only customer-generated event
 * log (see Lead's own docblock), never authored, edited, or deleted
 * through the admin panel.
 */
class LeadPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'leads';
    }
}
