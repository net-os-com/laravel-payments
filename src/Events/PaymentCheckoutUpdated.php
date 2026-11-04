<?php

namespace NetOS\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NetOS\Payments\Enums\Provider;

/**
 * A checkout changed at the provider (paid or expired). The app retrieves it to see what changed; the package does
 * not know what was being paid for.
 */
class PaymentCheckoutUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Provider $provider,
        public readonly string $providerAccountId,
        public readonly string $providerCheckoutId,
    ) {}
}
