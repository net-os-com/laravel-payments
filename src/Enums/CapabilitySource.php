<?php

namespace NetOS\Payments\Enums;

/**
 * Where a capability runs: at the payment provider, or in the application.
 */
enum CapabilitySource: string
{
    case STRIPE = 'stripe';
    case LOCAL = 'local';
}
