<?php

namespace App\Services\Firebase;

use RuntimeException;

/**
 * Firebase answered, and said no. `reason` is Google's code (EMAIL_EXISTS,
 * INVALID_LOGIN_CREDENTIALS, …) without the free-text detail Google
 * sometimes appends after it.
 *
 * Distinct from a plain RuntimeException (no answer at all — network,
 * credentials), which callers treat as "Firebase is unreachable".
 */
class FirebaseAuthException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message.': '.$reason);
    }
}
