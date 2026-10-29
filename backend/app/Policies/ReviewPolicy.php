<?php

namespace App\Policies;

use App\Models\User;

/**
 * Review Management permissions (SPEC section 5 item 6, task 5.5).
 *
 * No create/update/delete methods: admin never authors or edits a
 * review, only hides/unhides one via hide().
 */
class ReviewPolicy extends AdminModulePolicy
{
    protected function module(): string
    {
        return 'reviews';
    }

    public function hide(User $user): bool
    {
        return $user->hasPermission('reviews.hide');
    }
}
