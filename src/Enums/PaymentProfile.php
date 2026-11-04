<?php

namespace NetOS\Payments\Enums;

use NetOS\Payments\Data\CapabilitySourcesData;

/**
 * The allowed combinations of invoicing, tax and discount code sources, as presets.
 */
enum PaymentProfile: string
{
    /**
     * The provider handles invoices, tax and discount codes inside its checkout.
     */
    case STRIPE_MANAGED = 'stripe_managed';

    /**
     * The provider invoices and calculates tax; discount codes are the application's own.
     */
    case STRIPE_WITH_LOCAL_DISCOUNTS = 'stripe_with_local_discounts';

    /**
     * The provider only takes the money; the application invoices, calculates tax and owns the codes.
     */
    case LOCALLY_MANAGED = 'locally_managed';

    public function sources(): CapabilitySourcesData
    {
        $stripe = CapabilitySource::STRIPE;
        $local = CapabilitySource::LOCAL;

        return match ($this) {
            self::STRIPE_MANAGED => new CapabilitySourcesData(invoicing: $stripe, tax: $stripe, discounts: $stripe),
            self::STRIPE_WITH_LOCAL_DISCOUNTS => new CapabilitySourcesData(invoicing: $stripe, tax: $stripe, discounts: $local),
            self::LOCALLY_MANAGED => new CapabilitySourcesData(invoicing: $local, tax: $local, discounts: $local),
        };
    }
}
