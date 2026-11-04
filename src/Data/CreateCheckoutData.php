<?php

namespace NetOS\Payments\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class CreateCheckoutData extends Data
{
    /**
     * @param array<CheckoutLineItemData> $lineItems
     * @param array<string, string> $metadata
     */
    public function __construct(
        #[DataCollectionOf(CheckoutLineItemData::class)]
        public array $lineItems,
        public string $currency,
        public string $customerEmail,
        public string $successUrl,
        public string $cancelUrl,
        public CarbonImmutable $expiresAt,
        /** The provider's code to apply when the provider handles discount codes. */
        public ?string $providerDiscountId,
        public string $description,
        /** Printed at the bottom of the provider's invoice. */
        public ?string $invoiceFooter = null,
        public array $metadata = [],
    ) {}
}
