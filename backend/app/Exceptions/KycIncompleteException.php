<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A vendor account cannot be approved until every compulsory KYC document
 * is Verified (VendorKycService). Thrown by User::recordApprovalDecision(),
 * the one place every approval passes through, so no screen can skip it.
 */
class KycIncompleteException extends RuntimeException
{
    /** @param  array<int, string>  $missing  document type names */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(self::describe($missing));
    }

    /** @param  array<int, string>  $missing */
    public static function describe(array $missing): string
    {
        return 'These compulsory documents must be uploaded and verified first: '
            .implode(', ', $missing).'.';
    }
}
