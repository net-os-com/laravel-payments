<?php

namespace NetOS\Payments\Drivers\Stripe;

use LogicException;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Exceptions\HandledByCheckoutException;
use NetOS\Payments\PaymentContext;
use Stripe\StripeClient;

/**
 * Stripe creates, numbers, renders and emails the invoice inside Checkout; the application only reads it.
 */
class StripeInvoiceDriver implements ProvidesInvoices
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function isHandledByCheckout(): bool
    {
        return true;
    }

    public function issue(PaymentContext $context, IssueInvoiceData $data): InvoiceData
    {
        throw new HandledByCheckoutException('Invoicing');
    }

    public function find(PaymentContext $context, string $invoiceReference): InvoiceData
    {
        $invoice = $this->stripe->invoices->retrieve($invoiceReference, [], ['stripe_account' => $context->requireAccount()]);

        return new InvoiceData(
            source: CapabilitySource::STRIPE,
            reference: $invoice->id,
            number: $invoice->number,
            status: (string) $invoice->status,
            hostedUrl: $invoice->hosted_invoice_url,
            pdfUrl: $invoice->invoice_pdf,
        );
    }

    public function creditNote(PaymentContext $context, string $invoiceReference, int $amount): InvoiceData
    {
        throw new LogicException('Stripe credit notes are not part of the POC.');
    }
}
