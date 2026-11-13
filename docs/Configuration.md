# Configuration

[Home](Home.md) › Configuration

## Service provider

`NetOS\Payments\PaymentsServiceProvider` is auto-discovered.

| Phase | What it does |
| --- | --- |
| `register()` | `mergeConfigFrom(config/payments.php, 'payments')`; singletons for the seven managers (`AccountManager`, `CheckoutManager`, `RefundManager`, `InvoiceManager`, `TaxManager`, `DiscountCodeManager`, `WebhookManager`); singleton `NetOS\Payments\Payments`; singleton `Stripe\StripeClient` with `['api_key' => config('payments.stripe.secret') ?: null]`. |
| `boot()` | `loadMigrationsFrom(database/migrations)` (central only); `loadRoutesFrom(routes/webhooks.php)`; `loadRoutesFrom(routes/documents.php)`; `loadViewsFrom(resources/views, 'payments')`; `publishes([config/payments.php => config_path('payments.php')], 'payments-config')`; `publishes([database/migrations => database_path('migrations')], 'payments-migrations')`; in the console, `commands([RetryWebhookEventsCommand::class])`; `publishes([resources/views => resource_path('views/vendor/payments')], 'payments-views')`. |

The facade alias `Payments` points at `NetOS\Payments\Facades\Payments`.

Because `StripeClient` is a container singleton, a test or a host app can replace it with its own binding (for example a client pointed at `stripe-mock`). Because the managers are singletons too, a driver registered with `extend()` on a manager is the one the facade uses (see [Extending](Extending.md)).

## Config keys

```php
return [
    'connections' => [
        'central' => env('PAYMENTS_CENTRAL_CONNECTION'),
        'tenant' => env('PAYMENTS_TENANT_CONNECTION'),
    ],
    'queue' => env('PAYMENTS_QUEUE', 'payments'),
    'webhook_path' => 'payments/webhooks',
    'routes' => [
        'documents_prefix' => 'payments/documents',
        'documents_middleware' => [],
    ],
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secrets' => [
            'snapshot' => env('STRIPE_WEBHOOK_SECRET'),
            'thin' => env('STRIPE_THIN_WEBHOOK_SECRET'),
        ],
        'country' => env('STRIPE_ACCOUNT_COUNTRY', 'nl'),
        'currency' => env('STRIPE_ACCOUNT_CURRENCY', 'eur'),
    ],
    'local' => [
        'invoice_number_prefix' => env('PAYMENTS_INVOICE_PREFIX', 'INV'),
        'credit_note_number_prefix' => env('PAYMENTS_CREDIT_NOTE_PREFIX', 'CN'),
        'invoice_disk' => env('PAYMENTS_INVOICE_DISK', 'local'),
        'tax_country' => env('PAYMENTS_TAX_COUNTRY', 'nl'),
    ],
];
```

| Key | Env var | Default | Read by | Notes |
| --- | --- | --- | --- | --- |
| `connections.central` | `PAYMENTS_CENTRAL_CONNECTION` | `null` | `PaymentAccount`, `PaymentWebhookEvent`, central migrations | `null` falls back to the model's default connection. In a stancl/tenancy app set it to the central connection name, otherwise the models follow the tenant connection while tenancy is initialized. |
| `connections.tenant` | `PAYMENTS_TENANT_CONNECTION` | `null` | `PaymentDiscountCode`, `PaymentTaxRate`, `PaymentInvoice`, `PaymentDocumentSequence` (`UsesTenantConnection`), tenant migrations (`getConnection()`), `LocalInvoiceDriver` transaction | `null` means the default connection, which stancl/tenancy switches to the tenant database. |
| `queue` | `PAYMENTS_QUEUE` | `payments` | `ProcessWebhookEventJob` and `RenderInvoicePdfJob` constructors (`onQueue`) | A worker must listen to this queue. |
| `webhook_path` | none | `payments/webhooks` | `routes/webhooks.php` | Route: `POST {webhook_path}/{provider}`, name `payments.webhooks`. Not an env var; publish the config to change it. |
| `routes.documents_prefix` | none | `payments/documents` | `routes/documents.php` | Signed routes of local documents: `GET {prefix}/{document}` (`payments.documents.show`) and `GET {prefix}/{document}/pdf` (`payments.documents.pdf`). See [Invoices](Invoices.md#hosted-page-and-pdf). |
| `routes.documents_middleware` | none | `[]` | `routes/documents.php` | Middleware added after `signed:relative` (links are signed on path and query only, so they survive proxies that change the scheme or host), e.g. the middleware that initializes tenancy from a URL parameter. |
| `stripe.secret` | `STRIPE_SECRET` | `null` | `StripeClient` singleton | Platform account secret key. `StripeAccountDriver::dashboardUrl()` checks whether it starts with `sk_test_` or `rk_test_`. |
| `stripe.webhook_secrets.snapshot` | `STRIPE_WEBHOOK_SECRET` | `null` | `StripeWebhookDriver` | Verifies non-thin events. |
| `stripe.webhook_secrets.thin` | `STRIPE_THIN_WEBHOOK_SECRET` | `null` | `StripeWebhookDriver` | Verifies events whose JSON has `"object": "v2.core.event"`. |
| `stripe.country` | `STRIPE_ACCOUNT_COUNTRY` | `nl` | `StripeAccountDriver` (`identity.country`), `StripeTaxRates` (tax rate `country` and `jurisdiction`, upper-cased) | One country for all new accounts. |
| `stripe.currency` | `STRIPE_ACCOUNT_CURRENCY` | `eur` | `StripeAccountDriver` (`defaults.currency`) | Checkout currency comes from `CreateCheckoutData::$currency`, not from here. |
| `local.invoice_number_prefix` | `PAYMENTS_INVOICE_PREFIX` | `INV` | `InvoiceManager` → `LocalInvoiceDriver` | Series of local invoices; numbers look like `INV-2026-0001`. |
| `local.credit_note_number_prefix` | `PAYMENTS_CREDIT_NOTE_PREFIX` | `CN` | `InvoiceManager` → `LocalInvoiceDriver` | Series of local credit notes; numbers look like `CN-2026-0001`. |
| `local.invoice_disk` | `PAYMENTS_INVOICE_DISK` | `local` | `RenderInvoicePdfJob` | Filesystem disk the PDFs of local documents are stored on. Web and queue workers must both reach it. |
| `local.tax_country` | `PAYMENTS_TAX_COUNTRY` | `nl` | `LocalTaxDriver` | Upper-cased before querying `payment_tax_rates.country`. |

## Environment example

```dotenv
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_THIN_WEBHOOK_SECRET=
STRIPE_ACCOUNT_COUNTRY=nl
STRIPE_ACCOUNT_CURRENCY=eur
PAYMENTS_CENTRAL_CONNECTION=mysql
PAYMENTS_TENANT_CONNECTION=
PAYMENTS_QUEUE=payments
PAYMENTS_INVOICE_PREFIX=INV
PAYMENTS_CREDIT_NOTE_PREFIX=CN
PAYMENTS_INVOICE_DISK=local
PAYMENTS_TAX_COUNTRY=nl
```

## Connections in practice

| App type | `central` | `tenant` |
| --- | --- | --- |
| Single database | `null` | `null` |
| stancl/tenancy, database per tenant | the central connection (e.g. `mysql`) | `null` (the tenant connection tenancy sets as default) |
| Separate payments database | its connection name | its connection name |

The reference host app sets `central` to `env('DB_CONNECTION', 'mysql')` and `tenant` to `null` in its published config.

## Migrations

| Path | Loaded by the package | Contents |
| --- | --- | --- |
| `database/migrations` | Yes, `loadMigrationsFrom()` | `payment_accounts`, `payment_webhook_events` on `connections.central` (via `Schema::connection(...)`) |
| `database/migrations/tenant` | **No** | `payment_discount_codes`, `payment_tax_rates`, `payment_invoices`, `payment_document_sequences` on `connections.tenant` (via the migration's `getConnection()`) |

`loadMigrationsFrom()` does not descend into subdirectories, so the tenant path must be registered by the app.

Both groups can also be published:

```bash
php artisan vendor:publish --tag=payments-migrations
```

This copies `database/migrations`, including the `tenant` subdirectory, to the app's `database/migrations`, so the tenant migrations land in `database/migrations/tenant`. Then register only that app path for tenant migrations instead of the vendor path below.

**stancl/tenancy:**

```php
// config/tenancy.php
'migration_parameters' => [
    '--force' => true,
    '--path' => [
        database_path('migrations/tenant'),
        base_path('vendor/net-os/laravel-payments/database/migrations/tenant'),
    ],
    '--realpath' => true,
],
```

**Single database:** call `$this->loadMigrationsFrom(base_path('vendor/net-os/laravel-payments/database/migrations/tenant'))` from a service provider, or pass `--path` to `migrate`.

The package does not seed `payment_tax_rates`. With local tax, the app must insert at least one rate per category it uses (see [Tax](Tax.md)).

## Queue

Webhook processing runs in `ProcessWebhookEventJob` on `payments.queue`, with `$tries = 5` and `$backoff = [10, 60, 300, 900]`; it sets no timeout of its own. `php artisan payments:retry-webhooks` queues stored events that are still unprocessed. See [Webhooks](Webhooks.md#operations).

PDF rendering of local documents runs in `RenderInvoicePdfJob` on the same queue, with `$tries = 3` and `$backoff = [10, 60]`.

## PDF rendering

Local documents are rendered with `spatie/laravel-pdf` (`^2.0`, a dependency of the package). `RenderInvoicePdfJob` calls `Pdf::view(...)` without choosing a driver, so the driver configured for spatie/laravel-pdf in the host app is used (`LARAVEL_PDF_DRIVER`). Configure that package as well, for example its Cloudflare driver with its API token and account id. See [Security and Limitations](Security-and-Limitations.md#documents-and-pdfs) for what the renderer receives.

## Views

The package registers its views under the namespace `payments`. The local invoice and credit note use `payments::invoices.document` (`resources/views/invoices/document.blade.php`), both for the hosted page and for the PDF.

```bash
php artisan vendor:publish --tag=payments-views
```

This copies the views to `resources/views/vendor/payments`, where Laravel picks them up before the package's own.
