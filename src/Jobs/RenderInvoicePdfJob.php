<?php

namespace NetOS\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use NetOS\Payments\Events\PaymentInvoiceIssued;
use NetOS\Payments\Models\PaymentInvoice;
use NetOS\Payments\Support\LocalDocumentPresenter;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Renders a local invoice or credit note to PDF once, stores it, and announces that the document is ready.
 * The renderer is the one configured for spatie/laravel-pdf (LARAVEL_PDF_DRIVER).
 */
class RenderInvoicePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param array<string, string> $urlParameters
     */
    public function __construct(
        public readonly int $documentId,
        public readonly array $urlParameters,
    ) {
        $this->onQueue(config('payments.queue'));
    }

    public function handle(LocalDocumentPresenter $presenter): void
    {
        $document = PaymentInvoice::query()->with('creditedInvoice')->findOrFail($this->documentId);

        if ($document->pdf_path === null) {
            $disk = (string) config('payments.local.invoice_disk');
            // A random part keeps paths unique even when several applications or tenants share a disk.
            $path = sprintf('invoices/%s/%s-%s.pdf', $document->issued_at->format('Y'), $document->number, Str::lower(Str::random(16)));

            Pdf::view('payments::invoices.document', ['document' => $document, 'pdfUrl' => null])
                ->format('a4')
                ->disk($disk)
                ->save($path);

            $document->update(['pdf_disk' => $disk, 'pdf_path' => $path]);
        }

        PaymentInvoiceIssued::dispatch(
            $presenter->present($document, $this->urlParameters),
            $document->reference,
            $document->payer_email,
            $document->pdf_disk,
            $document->pdf_path,
        );
    }
}
