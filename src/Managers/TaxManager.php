<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\CalculatesTax;
use NetOS\Payments\Drivers\Local\LocalTaxDriver;
use NetOS\Payments\Drivers\Stripe\StripeTaxDriver;

/**
 * @method CalculatesTax driver(?string $driver = null)
 */
class TaxManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): CalculatesTax
    {
        return new StripeTaxDriver();
    }

    public function createLocalDriver(): CalculatesTax
    {
        return new LocalTaxDriver($this->config->get('payments.local.tax_country'));
    }
}
