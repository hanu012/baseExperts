<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when master data is deleted while a subscription still references it.
 *
 * subscription_items stores item_id without a foreign key, because it points
 * at three different tables depending on item_type. That means the database
 * cannot refuse this on its own — this exception is the substitute.
 */
class RecordInUseException extends RuntimeException
{
    public static function referencedBySubscriptions(string $label, int $count): self
    {
        return new self(
            "{$label} cannot be deleted: it is referenced by {$count} "
            .($count === 1 ? 'subscription' : 'subscriptions').'. '
            .'Deactivate it instead (is_active = false), which hides it from '
            .'new selections while leaving existing subscriptions intact.'
        );
    }
}
