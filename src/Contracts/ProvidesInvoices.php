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
     * Credits (part of) an invoice, e.g. after a refund.
     */
    public function creditNote(PaymentContext $context, string $invoiceReference, int $amount): InvoiceData;
}
