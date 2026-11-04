<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\IssuesRefunds;
use NetOS\Payments\Drivers\Stripe\StripeRefundDriver;
use Stripe\StripeClient;

/**
 * @method IssuesRefunds driver(?string $driver = null)
 */
class RefundManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): IssuesRefunds
    {
        return new StripeRefundDriver($this->container->make(StripeClient::class));
    }
}
