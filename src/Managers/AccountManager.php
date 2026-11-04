<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\ManagesAccounts;
use NetOS\Payments\Drivers\Stripe\StripeAccountDriver;
use Stripe\StripeClient;

/**
 * @method ManagesAccounts driver(?string $driver = null)
 */
class AccountManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): ManagesAccounts
    {
        return new StripeAccountDriver(
            $this->container->make(StripeClient::class),
            $this->config->get('payments.stripe.country'),
            $this->config->get('payments.stripe.currency'),
        );
    }
}
