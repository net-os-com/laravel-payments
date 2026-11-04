<?php

namespace NetOS\Payments\Enums;

enum CheckoutStatus: string
{
    /**
     * Waiting for the customer to pay.
     */
    case OPEN = 'open';

    /**
     * Paid.
     */
    case PAID = 'paid';

    /**
     * Expired or cancelled before payment.
     */
    case EXPIRED = 'expired';
}
