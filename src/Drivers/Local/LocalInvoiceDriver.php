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
use InvalidArgumentException;
use RuntimeException;

/**
 * Invoices and credit notes issued by the application, each series numbered per year without gaps.
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

    public function creditNote(
        PaymentContext $context,
        string $invoiceReference,
        int $amount,
        ?string $providerRefundId = null,
    ): InvoiceData {
        $creditNote = DB::connection(config('payments.connections.tenant'))->transaction(
            function () use ($invoiceReference, $amount): PaymentInvoice {
                $invoice = PaymentInvoice::query()->where('type', 'invoice')->lockForUpdate()->findOrFail($invoiceReference);
                $remaining = $invoice->total - $invoice->credited_amount;

                if ($amount <= 0 || $amount > $remaining) {
                    throw new InvalidArgumentException('Credit exceeds the remaining amount.');
                }

                $issuedAt = now();
                $lines = $this->creditLines($invoice, $amount);

                $creditNote = PaymentInvoice::query()->create([
                    'type' => 'credit_note',
                    'credits_invoice_id' => $invoice->id,
                    'number' => $this->numberer->next($this->creditNoteSeries, $issuedAt),
                    'status' => 'issued',
                    'reference' => $invoice->reference,
                    'issuer' => $invoice->issuer,
                    'payer_name' => $invoice->payer_name,
                    'payer_email' => $invoice->payer_email,
                    'payer' => $invoice->payer,
                    'lines' => $lines,
                    'discount_amount' => 0,
                    'tax_amount' => array_sum(array_column($lines, 'taxAmount')),
                    'total' => $amount,
                    'currency' => $invoice->currency,
                    'issued_at' => $issuedAt,
                ]);

                $creditedAmount = $invoice->credited_amount + $amount;
                $invoice->update([
                    'credited_amount' => $creditedAmount,
                    'credited_at' => $issuedAt,
                    'status' => $creditedAmount >= $invoice->total ? 'credited' : 'partially_credited',
                ]);

                return $creditNote;
            },
        );

        return $this->toData($creditNote);
    }

    /**
     * One line per VAT rate of the invoice, the credited amount split in proportion to those rates' gross amounts.
     *
     * @return list<array{description: string, amount: int, taxPercentage: float, taxAmount: int}>
     */
    private function creditLines(PaymentInvoice $invoice, int $amount): array
    {
        $grossPerRate = [];

        foreach ($invoice->lines as $line) {
            $rate = (string) (float) $line['taxPercentage'];
            $grossPerRate[$rate] = ($grossPerRate[$rate] ?? 0) + (int) $line['amount'];
        }

        $invoiceGross = max(1, array_sum($grossPerRate));
        $description = sprintf('Refund for invoice %s', $invoice->number);
        $lines = [];
        $assigned = 0;
        $rates = array_keys($grossPerRate);

        foreach ($rates as $index => $rate) {
            $lineAmount = $index === count($rates) - 1
                ? $amount - $assigned
                : (int) round($amount * $grossPerRate[$rate] / $invoiceGross);
            $assigned += $lineAmount;
            $percentage = (float) $rate;

            $lines[] = [
                'description' => $description,
                'amount' => $lineAmount,
                'taxPercentage' => $percentage,
                'taxAmount' => $lineAmount - (int) round($lineAmount * 100 / (100 + $percentage)),
            ];
        }

        return $lines;
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
            type: $invoice->type,
            creditsReference: $invoice->credits_invoice_id === null ? null : (string) $invoice->credits_invoice_id,
        );
    }
}
