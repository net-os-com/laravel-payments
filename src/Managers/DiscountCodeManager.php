<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\ManagesDiscountCodes;
use NetOS\Payments\Drivers\Local\LocalDiscountCodeDriver;
use NetOS\Payments\Drivers\Stripe\StripeDiscountCodeDriver;
use Stripe\StripeClient;

/**
 * @method ManagesDiscountCodes driver(?string $driver = null)
 */
class DiscountCodeManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): ManagesDiscountCodes
    {
        return new StripeDiscountCodeDriver($this->container->make(StripeClient::class));
    }

    public function createLocalDriver(): ManagesDiscountCodes
    {
        return new LocalDiscountCodeDriver();
    }
}
