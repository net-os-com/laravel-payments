<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentInvoice;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->ctx = $this->localContext();
    $this->invoice = Payments::invoices($this->ctx)->issue($this->ctx, new IssueInvoiceData(
        'Guest', 'guest@example.com', [new TaxedLineData('Desk, 09:00 - 12:00', 3000, 21.0, 521)], 600, 'eur', 'payment-1',
    ));
});

it('shows the hosted document behind a signed link', function (): void {
    $this->get($this->invoice->hostedUrl)
        ->assertOk()
        ->assertSee($this->invoice->number)
        ->assertSee('Operator B.V.')
        ->assertSee('NL123456789B01')
        ->assertSee('€30,00')
        ->assertSee('-€6,00')
        ->assertDontSee('Download PDF');
});

it('rejects a tampered link', function (): void {
    $this->get($this->invoice->hostedUrl . 'x')->assertForbidden();
    $this->get(str_replace('tenant=acme', 'tenant=other', $this->invoice->hostedUrl))->assertForbidden();
});

it('returns 404 for the PDF until it is rendered and while the file is missing', function (): void {
    $pdfUrl = NetOS\Payments\Support\LocalDocumentPresenter::signedUrl(
        'payments.documents.pdf',
        ['document' => $this->invoice->reference, 'tenant' => 'acme'],
    );

    $this->get($pdfUrl)->assertNotFound();

    PaymentInvoice::query()->whereKey($this->invoice->reference)->update(['pdf_disk' => 'local', 'pdf_path' => 'invoices/missing.pdf']);
    $this->get($pdfUrl)->assertNotFound();

    Storage::disk('local')->put('invoices/missing.pdf', '%PDF-1.4 test');
    $this->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->get(Payments::invoices($this->ctx)->find($this->ctx, $this->invoice->reference)->hostedUrl)->assertSee('Download PDF');
});

it('keeps links valid when the scheme or host differs, as behind a proxy', function (): void {
    $relative = parse_url($this->invoice->hostedUrl, PHP_URL_PATH) . '?' . parse_url($this->invoice->hostedUrl, PHP_URL_QUERY);

    $this->get('http://internal.example' . $relative)->assertOk();
});
