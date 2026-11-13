<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Exceptions\HandledByCheckoutException;
use NetOS\Payments\PaymentContext;
use Stripe\StripeClient;

/**
 * Stripe creates, numbers, renders and emails the invoice inside Checkout; the application reads it and adds credit
 * notes after refunds.
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
        $credited = (int) $invoice->post_payment_credit_notes_amount;

        return new InvoiceData(
            source: CapabilitySource::STRIPE,
            reference: $invoice->id,
            number: $invoice->number,
            // The same statuses as local invoices once credit notes exist.
            status: match (true) {
                $credited > 0 && $credited >= $invoice->total => 'credited',
                $credited > 0 => 'partially_credited',
                default => (string) $invoice->status,
            },
            hostedUrl: $invoice->hosted_invoice_url,
            pdfUrl: $invoice->invoice_pdf,
        );
    }

    public function creditNote(
        PaymentContext $context,
        string $invoiceReference,
        int $amount,
        ?string $providerRefundId = null,
    ): InvoiceData {
        $params = ['invoice' => $invoiceReference, 'amount' => $amount];

        // Linking the existing refund keeps Stripe from refunding the amount a second time.
        if ($providerRefundId !== null) {
            $params['refunds'] = [['refund' => $providerRefundId, 'amount_refunded' => $amount]];
        }

        $creditNote = $this->stripe->creditNotes->create($params, ['stripe_account' => $context->requireAccount()]);

        return new InvoiceData(
            source: CapabilitySource::STRIPE,
            reference: $creditNote->id,
            number: $creditNote->number,
            status: (string) $creditNote->status,
            hostedUrl: null,
            pdfUrl: $creditNote->pdf,
            type: 'credit_note',
            creditsReference: $invoiceReference,
        );
    }
}
