<?php

namespace NetOS\Payments\Drivers\Local;

use Illuminate\Support\Facades\DB;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Models\PaymentInvoice;
use NetOS\Payments\PaymentContext;
use NetOS\Payments\Jobs\RenderInvoicePdfJob;
use NetOS\Payments\Support\DocumentNumberer;
use NetOS\Payments\Support\LocalDocumentPresenter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Invoices and credit notes issued by the application, each series numbered per year without gaps.
 */
class LocalInvoiceDriver implements ProvidesInvoices
{
    public function __construct(
        private readonly DocumentNumberer $numberer,
        private readonly LocalDocumentPresenter $presenter,
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

        RenderInvoicePdfJob::dispatch($invoice->id, $context->urlParameters)->afterCommit();

        return $this->presenter->present($invoice, $context->urlParameters);
    }

    public function find(PaymentContext $context, string $invoiceReference): InvoiceData
    {
        return $this->presenter->present(PaymentInvoice::query()->findOrFail($invoiceReference), $context->urlParameters);
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

        RenderInvoicePdfJob::dispatch($creditNote->id, $context->urlParameters)->afterCommit();

        return $this->presenter->present($creditNote, $context->urlParameters);
    }

    /**
     * One line per VAT rate of the invoice. Crediting what is left mirrors the invoice's remaining amounts and VAT per
     * rate, so a full credit cancels the VAT to the cent. A partial credit is split over the rates in proportion to
     * their gross amounts (largest remainder, never negative), with VAT calculated per line.
     *
     * @return list<array{description: string, amount: int, taxPercentage: float, taxAmount: int}>
     */
    private function creditLines(PaymentInvoice $invoice, int $amount): array
    {
        $grossPerRate = [];
        $taxPerRate = [];

        foreach ($invoice->lines as $line) {
            $rate = (string) (float) $line['taxPercentage'];
            $grossPerRate[$rate] = ($grossPerRate[$rate] ?? 0) + (int) $line['amount'];
            $taxPerRate[$rate] = ($taxPerRate[$rate] ?? 0) + (int) $line['taxAmount'];
        }

        foreach ($invoice->creditNotes()->get() as $earlierCredit) {
            foreach ($earlierCredit->lines as $line) {
                $rate = (string) (float) $line['taxPercentage'];
                $grossPerRate[$rate] = ($grossPerRate[$rate] ?? 0) - (int) $line['amount'];
                $taxPerRate[$rate] = ($taxPerRate[$rate] ?? 0) - (int) $line['taxAmount'];
            }
        }

        $grossPerRate = array_filter($grossPerRate, fn (int $gross): bool => $gross > 0);
        $remaining = array_sum($grossPerRate);
        $description = sprintf('Refund for invoice %s', $invoice->number);
        $isFullCredit = $amount === $remaining;
        $amounts = $isFullCredit ? $grossPerRate : $this->split($amount, $grossPerRate);
        $lines = [];

        foreach ($amounts as $rate => $lineAmount) {
            if ($lineAmount === 0) {
                continue;
            }

            $percentage = (float) $rate;
            $lines[] = [
                'description' => $description,
                'amount' => $lineAmount,
                'taxPercentage' => $percentage,
                'taxAmount' => $isFullCredit
                    ? $taxPerRate[$rate]
                    : $lineAmount - (int) round($lineAmount * 100 / (100 + $percentage)),
            ];
        }

        return $lines;
    }

    /**
     * Splits an amount over weights without rounding drift: floor each share, then hand the leftover cents to the
     * largest remainders.
     *
     * @param array<string, int> $weights
     * @return array<string, int>
     */
    private function split(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = $amount * $weight / $total;
            $shares[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $shares[$key];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if (array_sum($shares) >= $amount) {
                break;
            }

            $shares[$key]++;
        }

        return $shares;
    }
}
