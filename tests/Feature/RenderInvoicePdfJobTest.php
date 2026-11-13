<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Events\PaymentInvoiceIssued;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Jobs\RenderInvoicePdfJob;
use NetOS\Payments\Models\PaymentInvoice;
use Spatie\LaravelPdf\Facades\Pdf;

it('renders the PDF once, stores it and announces the document', function (): void {
    Queue::fake();
    Pdf::fake();
    Event::fake([PaymentInvoiceIssued::class]);
    $ctx = $this->localContext();
    $invoice = Payments::invoices($ctx)->issue($ctx, new IssueInvoiceData('Guest', 'guest@example.com', [new TaxedLineData('Desk', 3000, 21.0, 521)], 0, 'eur', 'payment-1'));

    (new RenderInvoicePdfJob((int) $invoice->reference, ['tenant' => 'acme']))->handle(app(NetOS\Payments\Support\LocalDocumentPresenter::class));

    $document = PaymentInvoice::query()->find($invoice->reference);
    expect($document->pdf_disk)->toBe('local')
        ->and($document->pdf_path)->toMatch('#^invoices/\d{4}/INV-\d{4}-0001-[a-z0-9]{16}\.pdf$#');
    Pdf::assertSaved(fn ($pdf, string $path): bool => $path === $document->pdf_path);
    Event::assertDispatched(PaymentInvoiceIssued::class, fn (PaymentInvoiceIssued $event): bool => $event->reference === 'payment-1'
        && $event->payerEmail === 'guest@example.com'
        && $event->pdfPath === $document->pdf_path
        && $event->document->pdfUrl !== null);

    // A retry after the PDF exists only announces the document again.
    (new RenderInvoicePdfJob((int) $invoice->reference, ['tenant' => 'acme']))->handle(app(NetOS\Payments\Support\LocalDocumentPresenter::class));
    expect($document->refresh()->pdf_path)->toBe($document->pdf_path);
    Event::assertDispatchedTimes(PaymentInvoiceIssued::class, 2);
});

it('queues the PDF of a credit note too', function (): void {
    Queue::fake();
    $ctx = $this->localContext();
    $invoice = Payments::invoices($ctx)->issue($ctx, new IssueInvoiceData('Guest', 'guest@example.com', [new TaxedLineData('Desk', 3000, 21.0, 521)], 0, 'eur', 'payment-1'));

    $creditNote = Payments::invoices($ctx)->creditNote($ctx, $invoice->reference, 3000);

    Queue::assertPushed(RenderInvoicePdfJob::class, fn (RenderInvoicePdfJob $job): bool => $job->documentId === (int) $creditNote->reference);
});
