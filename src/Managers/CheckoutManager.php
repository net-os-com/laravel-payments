<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\ProcessesCheckouts;
use NetOS\Payments\Drivers\Stripe\StripeCheckoutDriver;
use NetOS\Payments\Drivers\Stripe\StripeTaxRates;
use Stripe\StripeClient;

/**
 * @method ProcessesCheckouts driver(?string $driver = null)
 */
class CheckoutManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): ProcessesCheckouts
    {
        $stripe = $this->container->make(StripeClient::class);

        return new StripeCheckoutDriver($stripe, new StripeTaxRates($stripe, $this->config->get('payments.stripe.country')));
    }
}
