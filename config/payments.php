<?php

return [
    /*
    | The database connections of the package's models. Central models (accounts, webhook events) live next to the
    | owner of an account; tenant models (payments, invoices) live wherever the application keeps its bookings.
    */
    'connections' => [
        'central' => env('PAYMENTS_CENTRAL_CONNECTION'),
        'tenant' => env('PAYMENTS_TENANT_CONNECTION'),
    ],

    // | The queue that processes inbound webhook events.
    'queue' => env('PAYMENTS_QUEUE', 'payments'),

    // | The webhook route is registered as {prefix}/{provider}.
    'webhook_path' => 'payments/webhooks',

    /*
    | The signed routes of local invoices and credit notes: {prefix}/{document} (page) and {prefix}/{document}/pdf.
    | Add the middleware that puts the request in the right context, e.g. the tenancy middleware.
    */
    'routes' => [
        'documents_prefix' => 'payments/documents',
        'documents_middleware' => [],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        // Each event destination signs with its own secret: one for snapshot events of connected accounts, one for
        // thin (v2) events.
        'webhook_secrets' => [
            'snapshot' => env('STRIPE_WEBHOOK_SECRET'),
            'thin' => env('STRIPE_THIN_WEBHOOK_SECRET'),
        ],
        'country' => env('STRIPE_ACCOUNT_COUNTRY', 'nl'),
        'currency' => env('STRIPE_ACCOUNT_CURRENCY', 'eur'),
    ],

    /*
    | The local drivers: invoices and credit notes numbered {prefix}-{year}-0001, and VAT from the payment_tax_rates
    | table of this country.
    */
    'local' => [
        'invoice_number_prefix' => env('PAYMENTS_INVOICE_PREFIX', 'INV'),
        'credit_note_number_prefix' => env('PAYMENTS_CREDIT_NOTE_PREFIX', 'CN'),
        // The disk the PDFs of local documents are stored on; web and queue workers must both reach it.
        'invoice_disk' => env('PAYMENTS_INVOICE_DISK', 'local'),
        'tax_country' => env('PAYMENTS_TAX_COUNTRY', 'nl'),
    ],
];
