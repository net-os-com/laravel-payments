<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\IssuesRefunds;
use NetOS\Payments\Data\ProviderRefundData;
use NetOS\Payments\PaymentContext;
use Stripe\StripeClient;

/**
 * Refunds a direct charge on the connected account; Stripe emails the receipt.
 */
class StripeRefundDriver implements IssuesRefunds
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function refund(PaymentContext $context, string $providerPaymentId, ?int $amount = null): ProviderRefundData
    {
        $refund = $this->stripe->refunds->create(array_filter([
            'payment_intent' => $providerPaymentId,
            'amount' => $amount,
        ], fn (mixed $value): bool => $value !== null), ['stripe_account' => $context->requireAccount()]);

        return new ProviderRefundData(
            providerRefundId: $refund->id,
            amount: $refund->amount,
            status: (string) $refund->status,
        );
    }
}
