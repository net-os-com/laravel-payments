<?php

namespace NetOS\Payments\Drivers\Local;

use Illuminate\Support\Facades\DB;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Models\PaymentInvoice;
use NetOS\Payments\PaymentContext;
use NetOS\Payments\Support\DocumentNumberer;
use RuntimeException;

/**
 * Invoices issued by the application, numbered per year without gaps. Skeleton for the POC: there is no PDF and no
 * email yet, and a credit is recorded on the invoice instead of as a separate credit note.
 */
class LocalInvoiceDriver implements ProvidesInvoices
{
    public function __construct(
        private readonly DocumentNumberer $numberer,
        private readonly string $invoiceSeries,
        private readonly string $creditNoteSeries,
    ) {}

    public function isHandledByCheckout(): bool
    {
        return false;
    }

    public function issue(PaymentContext $context, IssueInvoiceData $data): InvoiceData
    {
        if ($context->issuer === null) {
            throw new RuntimeException('Local invoices need an issuer.');
        }

        $invoice = DB::connection(config('payments.connections.tenant'))->transaction(function () use ($context, $data): PaymentInvoice {
            $issuedAt = now();

            return PaymentInvoice::query()->create([
                'type' => 'invoice',
                'number' => $this->numberer->next($this->invoiceSeries, $issuedAt),
                'status' => 'issued',
                'reference' => $data->reference,
                'issuer' => $context->issuer->toArray(),
                'payer_name' => $data->payerName,
                'payer_email' => $data->payerEmail,
                'payer' => $data->customer?->toArray(),
                'lines' => array_map(fn (TaxedLineData $line): array => $line->toArray(), $data->lines),
                'discount_amount' => $data->discountAmount,
                'tax_amount' => array_sum(array_map(fn (TaxedLineData $line): int => $line->taxAmount, $data->lines)),
                'total' => array_sum(array_map(fn (TaxedLineData $line): int => $line->amount, $data->lines)),
                'currency' => $data->currency,
                'issued_at' => $issuedAt,
            ]);
        });

        return $this->toData($invoice);
    }

    public function find(PaymentContext $context, string $invoiceReference): InvoiceData
    {
        return $this->toData(PaymentInvoice::query()->findOrFail($invoiceReference));
    }

    public function creditNote(PaymentContext $context, string $invoiceReference, int $amount): InvoiceData
    {
        $invoice = PaymentInvoice::query()->findOrFail($invoiceReference);
        $invoice->update([
            'status' => 'credited',
            'credited_amount' => $invoice->credited_amount + $amount,
            'credited_at' => now(),
        ]);

        return $this->toData($invoice);
    }

    private function toData(PaymentInvoice $invoice): InvoiceData
    {
        return new InvoiceData(
            source: CapabilitySource::LOCAL,
            reference: (string) $invoice->id,
            number: $invoice->number,
            status: $invoice->status,
            hostedUrl: null,
            pdfUrl: null,
        );
    }
}
