<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\PaymentContext;

interface ProvidesInvoices
{
    /**
     * True when the provider creates the invoice inside its checkout; the application then only reads it.
     */
    public function isHandledByCheckout(): bool;

    public function issue(PaymentContext $context, IssueInvoiceData $data): InvoiceData;

    public function find(PaymentContext $context, string $invoiceReference): InvoiceData;

    /**
     * Credits (part of) an invoice after a refund and returns the credit note. The amount can not exceed what is left
     * to credit. Pass the provider's refund id so the credit note is linked to that refund instead of refunding again.
     */
    public function creditNote(
        PaymentContext $context,
        string $invoiceReference,
        int $amount,
        ?string $providerRefundId = null,
    ): InvoiceData;
}
