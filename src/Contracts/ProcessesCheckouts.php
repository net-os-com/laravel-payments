<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\CreateCheckoutData;
use NetOS\Payments\Data\ProviderCheckoutData;
use NetOS\Payments\PaymentContext;

/**
 * A hosted payment page on the merchant's account. What it adds (invoice, tax, discount codes) follows the context's
 * capability sources.
 */
interface ProcessesCheckouts
{
    public function create(PaymentContext $context, CreateCheckoutData $data): ProviderCheckoutData;

    public function fetch(PaymentContext $context, string $providerCheckoutId): ProviderCheckoutData;

    /**
     * Ends an open checkout so the customer can no longer pay.
     */
    public function expire(PaymentContext $context, string $providerCheckoutId): ProviderCheckoutData;
}
