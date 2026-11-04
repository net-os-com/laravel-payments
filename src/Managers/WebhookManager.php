<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\TranslatesWebhooks;
use NetOS\Payments\Drivers\Stripe\StripeWebhookDriver;
use NetOS\Payments\Enums\Provider;

/**
 * @method TranslatesWebhooks driver(?string $driver = null)
 */
class WebhookManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return Provider::STRIPE->value;
    }

    public function createStripeDriver(): TranslatesWebhooks
    {
        return new StripeWebhookDriver(
            $this->config->get('payments.stripe.webhook_secrets.snapshot'),
            $this->config->get('payments.stripe.webhook_secrets.thin'),
        );
    }
}
