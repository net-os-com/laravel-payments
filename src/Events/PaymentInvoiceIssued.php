<?php

namespace NetOS\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NetOS\Payments\Data\InvoiceData;

/**
 * An invoice or credit note the application has to deliver itself is ready: a local document once its PDF is stored,
 * or a provider credit note the provider does not email. The application sends it to the payer.
 */
class PaymentInvoiceIssued
{
    use Dispatchable;

    public function __construct(
        public readonly InvoiceData $document,
        /** What the document is for in the application, e.g. a payment uuid. */
        public readonly string $reference,
        public readonly ?string $payerEmail,
        /** Where the PDF is stored, to attach it; null when only $document->pdfUrl is available. */
        public readonly ?string $pdfDisk = null,
        public readonly ?string $pdfPath = null,
    ) {}
}
