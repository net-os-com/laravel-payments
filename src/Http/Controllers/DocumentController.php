<?php

namespace NetOS\Payments\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use NetOS\Payments\Models\PaymentInvoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hosted page and the PDF of a local invoice or credit note, behind signed links.
 */
class DocumentController extends Controller
{
    public function show(string $document): View
    {
        $model = PaymentInvoice::query()->with('creditedInvoice')->findOrFail($document);

        return view('payments::invoices.document', [
            'document' => $model,
            'pdfUrl' => $model->pdf_path === null
                ? null
                : URL::signedRoute('payments.documents.pdf', [...Arr::except(request()->query(), ['signature', 'expires']), 'document' => $model->id]),
        ]);
    }

    public function pdf(string $document): StreamedResponse
    {
        $model = PaymentInvoice::query()->findOrFail($document);

        abort_if($model->pdf_path === null || $model->pdf_disk === null, 404);

        return Storage::disk($model->pdf_disk)->response(
            $model->pdf_path,
            $model->number . '.pdf',
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }
}
