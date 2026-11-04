<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\ProcessesCheckouts;
use NetOS\Payments\Data\CheckoutLineItemData;
use NetOS\Payments\Data\CreateCheckoutData;
use NetOS\Payments\Data\ProviderCheckoutData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\CheckoutStatus;
use NetOS\Payments\PaymentContext;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Stripe Checkout as a direct charge on the connected account. The context's sources decide what Stripe adds:
 * an invoice, Stripe Tax and promotion codes, or nothing of that when the application handles it.
 */
class StripeCheckoutDriver implements ProcessesCheckouts
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeTaxRates $taxRates,
    ) {}

    public function create(PaymentContext $context, CreateCheckoutData $data): ProviderCheckoutData
    {
        $accountId = $context->requireAccount();
        $sources = $context->sources;
        $isStripeInvoicing = $sources->invoicing === CapabilitySource::STRIPE;
        $isStripeTax = $sources->tax === CapabilitySource::STRIPE;
        $isStripeDiscounts = $sources->discounts === CapabilitySource::STRIPE;

        $params = [
            'mode' => 'payment',
            'line_items' => array_map(fn (CheckoutLineItemData $item): array => array_filter([
                'quantity' => $item->quantity,
                'price_data' => [
                    'currency' => $data->currency,
                    'unit_amount' => $item->unitAmount,
                    'tax_behavior' => 'inclusive',
                    'product_data' => ['name' => $item->description],
                ],
                'tax_rates' => $isStripeTax || $item->taxPercentage === null
                    ? null
                    : [$this->taxRates->inclusive($accountId, $item->taxPercentage)],
            ], fn (mixed $value): bool => $value !== null), $data->lineItems),
            'customer_email' => $data->customerEmail,
            // Sends the receipt even when the account holder turned off receipt emails in their dashboard.
            'payment_intent_data' => [
                'description' => $data->description,
                'metadata' => $data->metadata,
                'receipt_email' => $data->customerEmail,
            ],
            'success_url' => $data->successUrl,
            'cancel_url' => $data->cancelUrl,
            'expires_at' => $data->expiresAt->getTimestamp(),
            'metadata' => $data->metadata,
        ];

        if ($isStripeTax) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        if ($isStripeInvoicing) {
            $params['customer_creation'] = 'always';
            $params['tax_id_collection'] = ['enabled' => true];
            $params['invoice_creation'] = [
                'enabled' => true,
                'invoice_data' => array_filter([
                    'description' => $data->description,
                    'footer' => $data->invoiceFooter,
                    'metadata' => $data->metadata,
                    'rendering_options' => ['amount_tax_display' => 'include_inclusive_tax'],
                ]),
            ];
        }

        // A code the customer typed in the application is applied here; without one, Stripe's own field is offered.
        if ($isStripeDiscounts) {
            if ($data->providerDiscountId === null) {
                $params['allow_promotion_codes'] = true;
            } else {
                $params['discounts'] = [['promotion_code' => $data->providerDiscountId]];
            }
        }

        return $this->toData($this->stripe->checkout->sessions->create($params, ['stripe_account' => $accountId]));
    }

    public function fetch(PaymentContext $context, string $providerCheckoutId): ProviderCheckoutData
    {
        return $this->toData(
            $this->stripe->checkout->sessions->retrieve($providerCheckoutId, [], ['stripe_account' => $context->requireAccount()]),
        );
    }

    public function expire(PaymentContext $context, string $providerCheckoutId): ProviderCheckoutData
    {
        return $this->toData(
            $this->stripe->checkout->sessions->expire($providerCheckoutId, [], ['stripe_account' => $context->requireAccount()]),
        );
    }

    private function toData(Session $session): ProviderCheckoutData
    {
        $status = match (true) {
            $session->payment_status === 'paid' || $session->payment_status === 'no_payment_required' => CheckoutStatus::PAID,
            $session->status === 'expired' => CheckoutStatus::EXPIRED,
            default => CheckoutStatus::OPEN,
        };

        return new ProviderCheckoutData(
            providerCheckoutId: $session->id,
            url: $session->url,
            status: $status,
            amountTotal: $session->amount_total,
            amountDiscount: $session->total_details?->amount_discount,
            amountTax: $session->total_details?->amount_tax,
            providerPaymentId: is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent?->id,
            providerInvoiceId: is_string($session->invoice) ? $session->invoice : $session->invoice?->id,
            customerName: $session->customer_details?->name,
            metadata: $session->metadata?->toArray() ?? [],
        );
    }
}
