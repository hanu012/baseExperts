<?php

namespace App\Policies;

use App\Models\User;

/**
 * CMS Pages permissions (SPEC section 5 item 13).
 *
 * No delete() — see CmsPage's own docblock for why (fixed external
 * URL stability, not SPEC section 10's referential-integrity reason).
 */
class CmsPagePolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'pages';
    }
}
