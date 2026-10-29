<?php

namespace App\Policies;

/**
 * Plan module permissions (SPEC section 5.16).
 *
 * No delete method: SPEC section 10 / the RESTRICT foreign key mean these are
 * deactivated, never hard-deleted. Filament fails closed on a missing policy
 * method, so the absence itself denies any delete attempt.
 */
class PlanPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'plans';
    }
}
