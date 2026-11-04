<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\PaymentContext;

/**
 * Implemented by the application: the context of the current account holder (in Net OS, the current tenant).
 */
interface ResolvesPaymentContext
{
    public function current(): PaymentContext;
}
