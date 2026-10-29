<?php

namespace App\Policies;

/**
 * Report vendor queue permissions (SPEC section 4 item 10 / section
 * 5.15). viewAny/view only — inherited from AdminModulePolicy. There is
 * no create/update/delete: admins never author, edit, or delete a
 * report through this list, only read it. Full lifecycle actions
 * (resolve, assign, etc.) belong to Phase 6's Support Tickets module.
 */
class ReportPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'reports';
    }
}
