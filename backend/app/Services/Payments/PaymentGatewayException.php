<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * The gateway could not create a payment: unreachable, refused, or
 * switched on in Settings without keys in .env. Thrown inside the
 * subscribe transaction, so nothing half-created is left behind.
 */
class PaymentGatewayException extends RuntimeException {}
