<?php

namespace NetOS\Payments\Support;

use Illuminate\Support\Facades\URL;
use NetOS\Payments\Data\InvoiceData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Models\PaymentInvoice;

/**
 * A local document as InvoiceData, with signed links that do not expire (like a provider's hosted invoice link).
 * Only the path and query are signed, so the link stays valid behind proxies that change the scheme or host.
 */
class LocalDocumentPresenter
{
    /**
     * @param array<string, string> $urlParameters
     */
    public function present(PaymentInvoice $document, array $urlParameters): InvoiceData
    {
        $parameters = ['document' => $document->id, ...$urlParameters];

        return new InvoiceData(
            source: CapabilitySource::LOCAL,
            reference: (string) $document->id,
            number: $document->number,
            status: $document->status,
            hostedUrl: self::signedUrl('payments.documents.show', $parameters),
            pdfUrl: $document->pdf_path === null ? null : self::signedUrl('payments.documents.pdf', $parameters),
            type: $document->type,
            creditsReference: $document->credits_invoice_id === null ? null : (string) $document->credits_invoice_id,
        );
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public static function signedUrl(string $route, array $parameters): string
    {
        return url(URL::signedRoute($route, $parameters, absolute: false));
    }
}
