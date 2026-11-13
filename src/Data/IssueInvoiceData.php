<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class IssueInvoiceData extends Data
{
    /**
     * @param array<TaxedLineData> $lines after any discount
     */
    public function __construct(
        public string $payerName,
        public string $payerEmail,
        #[DataCollectionOf(TaxedLineData::class)]
        public array $lines,
        public int $discountAmount,
        public string $currency,
        /** What the invoice is for in the application, e.g. a payment uuid. */
        public string $reference,
        /** Billing details to print for the payer, e.g. taken from the provider's checkout. */
        public ?CustomerDetailsData $customer = null,
    ) {}
}
