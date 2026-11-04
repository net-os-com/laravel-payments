<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\CalculatesTax;
use NetOS\Payments\Exceptions\HandledByCheckoutException;
use NetOS\Payments\PaymentContext;

/**
 * Stripe Tax (automatic_tax) inside Checkout; the connected account needs its tax registrations set up in Stripe.
 */
class StripeTaxDriver implements CalculatesTax
{
    public function isHandledByCheckout(): bool
    {
        return true;
    }

    public function calculate(PaymentContext $context, array $lines): array
    {
        throw new HandledByCheckoutException('Tax');
    }
}
