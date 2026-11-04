<?php

namespace NetOS\Payments\Exceptions;

use LogicException;

/**
 * The capability runs inside the provider's checkout, so the application should not call it directly.
 */
class HandledByCheckoutException extends LogicException
{
    public function __construct(string $capability)
    {
        parent::__construct(sprintf('%s is handled by the provider\'s checkout.', $capability));
    }
}
