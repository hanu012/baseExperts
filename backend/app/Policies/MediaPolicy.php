<?php

namespace App\Policies;

use App\Models\User;

/**
 * Media moderation queue permissions (SPEC section 5.10/5.16).
 *
 * No create/update/delete methods: this queue only transitions
 * moderation_status via moderate(), it never creates, edits, or deletes a
 * media row.
 */
class MediaPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'media';
    }

    public function moderate(User $user): bool
    {
        return $user->hasPermission('media.moderate');
    }
}
