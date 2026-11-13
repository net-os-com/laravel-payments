<?php

namespace NetOS\Payments\Data;

use NetOS\Payments\Enums\CheckoutStatus;
use Spatie\LaravelData\Data;

class ProviderCheckoutData extends Data
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $providerCheckoutId,
        public ?string $url,
        public CheckoutStatus $status,
        /** Minor units, after discounts. */
        public ?int $amountTotal,
        public ?int $amountDiscount,
        public ?int $amountTax,
        public ?string $providerPaymentId,
        public ?string $providerInvoiceId,
        public ?string $customerName,
        public array $metadata = [],
        /** The billing details entered at checkout; null until the customer completed them. */
        public ?CustomerDetailsData $customer = null,
    ) {}
}
