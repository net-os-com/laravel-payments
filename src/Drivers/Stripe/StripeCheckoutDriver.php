<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\ProcessesCheckouts;
use NetOS\Payments\Data\CheckoutLineItemData;
use NetOS\Payments\Data\CreateCheckoutData;
use NetOS\Payments\Data\CustomerDetailsData;
use NetOS\Payments\Data\ProviderCheckoutData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\CheckoutStatus;
use NetOS\Payments\PaymentContext;
use InvalidArgumentException;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Stripe Checkout as a direct charge on the connected account. The context's sources decide what Stripe adds:
 * an invoice, Stripe Tax and promotion codes, or nothing of that when the application handles it.
 */
class StripeCheckoutDriver implements ProcessesCheckouts
{
    private const int MIN_EXPIRY_MINUTES = 30;

    private const int MAX_EXPIRY_MINUTES = 24 * 60;

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

        $this->assertValid($data, $isStripeTax);

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
            // Asked for every invoicing source, so a local invoice can show the same company details as Stripe's.
            'customer_creation' => 'always',
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
        ];

        if ($isStripeTax) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        if ($isStripeInvoicing) {
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

        // A code the customer typed in the application, which checked its start date, is applied here. Stripe's own
        // code field is never offered: Stripe promotion codes have no start date, so it would accept scheduled codes.
        if ($isStripeDiscounts && $data->providerDiscountId !== null) {
            $params['discounts'] = [['promotion_code' => $data->providerDiscountId]];
        }

        return $this->toData($this->stripe->checkout->sessions->create($params, ['stripe_account' => $accountId]));
    }

    /**
     * Stripe only accepts an expiry 30 minutes to 24 hours ahead, and a line without a rate under the application's tax
     * would silently be charged without VAT.
     */
    private function assertValid(CreateCheckoutData $data, bool $isStripeTax): void
    {
        $minutesAhead = now()->diffInMinutes($data->expiresAt, false);

        if ($minutesAhead < self::MIN_EXPIRY_MINUTES || $minutesAhead > self::MAX_EXPIRY_MINUTES) {
            throw new InvalidArgumentException('A Stripe checkout must expire between 30 minutes and 24 hours from now.');
        }

        if (! $isStripeTax) {
            foreach ($data->lineItems as $item) {
                if ($item->taxPercentage === null) {
                    throw new InvalidArgumentException(sprintf('Line "%s" has no tax percentage.', $item->description));
                }
            }
        }
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
            customer: $this->customerDetails($session),
        );
    }

    private function customerDetails(Session $session): ?CustomerDetailsData
    {
        $details = $session->customer_details;

        if ($details === null) {
            return null;
        }

        $address = $details->address;
        $taxId = $details->tax_ids[0] ?? null;

        return new CustomerDetailsData(
            name: $details->name,
            email: $details->email,
            companyName: $details->business_name ?? null,
            addressLine1: $address?->line1,
            addressLine2: $address?->line2,
            postalCode: $address?->postal_code,
            city: $address?->city,
            country: $address?->country,
            taxIdType: $taxId?->type,
            taxIdValue: $taxId?->value,
        );
    }
}
