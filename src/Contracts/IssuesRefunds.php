<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\ProviderRefundData;
use NetOS\Payments\PaymentContext;

interface IssuesRefunds
{
    /**
     * Returns money of a payment to the customer; the whole payment when no amount is given.
     */
    public function refund(PaymentContext $context, string $providerPaymentId, ?int $amount = null): ProviderRefundData;
}
