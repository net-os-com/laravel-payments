<?php

use Illuminate\Support\Facades\Queue;
use NetOS\Payments\Data\CustomerDetailsData;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Jobs\RenderInvoicePdfJob;
use NetOS\Payments\Models\PaymentInvoice;

beforeEach(function (): void {
    Queue::fake();
    $this->ctx = $this->localContext();
    $this->invoices = Payments::invoices($this->ctx);
});

function issueInvoice(object $test, array $lines, int $discount = 0): NetOS\Payments\Data\InvoiceData
{
    return $test->invoices->issue($test->ctx, new IssueInvoiceData(
        payerName: 'Guest',
        payerEmail: 'guest@example.com',
        lines: $lines,
        discountAmount: $discount,
        currency: 'eur',
        reference: 'payment-1',
        customer: new CustomerDetailsData(name: 'Guest', companyName: 'Acme B.V.', country: 'NL', taxIdValue: 'NL000099998B57'),
    ));
}

it('issues a numbered invoice with issuer, payer and totals', function (): void {
    $invoice = issueInvoice($this, [new TaxedLineData('Desk', 3000, 21.0, 521)], discount: 600);

    expect($invoice->number)->toBe('INV-' . now()->year . '-0001')
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->type)->toBe('invoice')
        ->and($invoice->pdfUrl)->toBeNull()
        ->and($invoice->hostedUrl)->toContain('/payments/documents/')->toContain('tenant=acme')->toContain('signature=');

    $this->assertDatabaseHas('payment_invoices', [
        'number' => $invoice->number,
        'total' => 3000,
        'tax_amount' => 521,
        'discount_amount' => 600,
        'payer_email' => 'guest@example.com',
    ]);
    expect(PaymentInvoice::query()->sole()->payer['companyName'])->toBe('Acme B.V.');
    Queue::assertPushed(RenderInvoicePdfJob::class, fn (RenderInvoicePdfJob $job): bool => $job->urlParameters === ['tenant' => 'acme']);
});

it('refuses to issue without an issuer', function (): void {
    $context = new NetOS\Payments\PaymentContext($this->ctx->provider, 'acct_test', $this->ctx->sources);

    Payments::invoices($context)->issue($context, new IssueInvoiceData('G', 'g@example.com', [], 0, 'eur', 'r'));
})->throws(RuntimeException::class, 'Local invoices need an issuer.');

it('credits part of an invoice and then the rest, never more', function (): void {
    $invoice = issueInvoice($this, [new TaxedLineData('Desk', 3000, 21.0, 521)]);

    $first = $this->invoices->creditNote($this->ctx, $invoice->reference, 1000);

    expect($first->type)->toBe('credit_note')
        ->and($first->number)->toBe('CN-' . now()->year . '-0001')
        ->and($first->creditsReference)->toBe($invoice->reference)
        ->and($this->invoices->find($this->ctx, $invoice->reference)->status)->toBe('partially_credited');

    expect(fn () => $this->invoices->creditNote($this->ctx, $invoice->reference, 2001))
        ->toThrow(InvalidArgumentException::class, 'Credit exceeds the remaining amount.');

    $this->invoices->creditNote($this->ctx, $invoice->reference, 2000);

    expect($this->invoices->find($this->ctx, $invoice->reference)->status)->toBe('credited');
    $this->assertDatabaseHas('payment_invoices', ['id' => $invoice->reference, 'credited_amount' => 3000]);
    expect(fn () => $this->invoices->creditNote($this->ctx, $invoice->reference, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a credit of zero or less', function (): void {
    $invoice = issueInvoice($this, [new TaxedLineData('Desk', 3000, 21.0, 521)]);

    $this->invoices->creditNote($this->ctx, $invoice->reference, 0);
})->throws(InvalidArgumentException::class);

it('cancels the invoice VAT to the cent on a full credit', function (): void {
    $invoice = issueInvoice($this, [new TaxedLineData('A', 1000, 21.0, 174), new TaxedLineData('B', 1000, 21.0, 174)]);

    $creditNote = $this->invoices->creditNote($this->ctx, $invoice->reference, 2000);

    $this->assertDatabaseHas('payment_invoices', ['id' => $creditNote->reference, 'tax_amount' => 348, 'total' => 2000]);
});

it('splits a partial credit over the VAT rates without negative lines', function (): void {
    $invoice = issueInvoice($this, [
        new TaxedLineData('Desk', 1, 21.0, 0),
        new TaxedLineData('Coffee', 1, 9.0, 0),
        new TaxedLineData('Water', 1, 0.0, 0),
    ]);

    $creditNote = $this->invoices->creditNote($this->ctx, $invoice->reference, 2);
    $amounts = array_column(PaymentInvoice::query()->find($creditNote->reference)->lines, 'amount');

    expect(array_sum($amounts))->toBe(2)
        ->and(min($amounts))->toBeGreaterThan(0);
});

it('credits what is left per rate after earlier credits', function (): void {
    $invoice = issueInvoice($this, [new TaxedLineData('Desk', 2000, 21.0, 347), new TaxedLineData('Coffee', 1000, 9.0, 83)]);

    $this->invoices->creditNote($this->ctx, $invoice->reference, 1500);
    $rest = $this->invoices->creditNote($this->ctx, $invoice->reference, 1500);

    $credited = PaymentInvoice::query()->where('type', 'credit_note')->get();
    expect($credited->sum('total'))->toBe(3000)
        ->and($credited->sum('tax_amount'))->toBe(347 + 83)
        ->and($this->invoices->find($this->ctx, $invoice->reference)->status)->toBe('credited')
        ->and($rest->number)->toBe('CN-' . now()->year . '-0002');
});
