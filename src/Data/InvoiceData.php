<?php

namespace NetOS\Payments\Data;

use NetOS\Payments\Enums\CapabilitySource;
use Spatie\LaravelData\Data;

class InvoiceData extends Data
{
    public function __construct(
        public CapabilitySource $source,
        /** The provider's invoice id, or the local invoice's id. */
        public string $reference,
        /** Null until the invoice is finalized. */
        public ?string $number,
        public string $status,
        /** A page where the customer views the invoice. */
        public ?string $hostedUrl,
        public ?string $pdfUrl,
        /** invoice or credit_note. */
        public string $type = 'invoice',
        /** For a credit note: the reference of the invoice it credits. */
        public ?string $creditsReference = null,
    ) {}
}
