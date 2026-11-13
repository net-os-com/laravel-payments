# Configuration

[Home](Home.md) › Configuration

## Service provider

`NetOS\Payments\PaymentsServiceProvider` is auto-discovered.

| Phase | What it does |
| --- | --- |
| `register()` | `mergeConfigFrom(config/payments.php, 'payments')`; singleton `NetOS\Payments\Payments`; singleton `Stripe\StripeClient` with `['api_key' => config('payments.stripe.secret') ?: null]`. |
| `boot()` | `loadMigrationsFrom(database/migrations)` (central only); `loadRoutesFrom(routes/webhooks.php)`; `publishes([config/payments.php => config_path('payments.php')], 'payments-config')`. |

The facade alias `Payments` points at `NetOS\Payments\Facades\Payments`.

Because `StripeClient` is a container singleton, a test or a host app can replace it with its own binding (for example a client pointed at `stripe-mock`).

## Config keys

```php
return [
    'connections' => [
        'central' => env('PAYMENTS_CENTRAL_CONNECTION'),
        'tenant' => env('PAYMENTS_TENANT_CONNECTION'),
    ],
    'queue' => env('PAYMENTS_QUEUE', 'payments'),
    'webhook_path' => 'payments/webhooks',
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
        'tax_country' => env('PAYMENTS_TAX_COUNTRY', 'nl'),
    ],
];
```

| Key | Env var | Default | Read by | Notes |
| --- | --- | --- | --- | --- |
| `connections.central` | `PAYMENTS_CENTRAL_CONNECTION` | `null` | `PaymentAccount`, `PaymentWebhookEvent`, central migrations | `null` falls back to the model's default connection. In a stancl/tenancy app set it to the central connection name, otherwise the models follow the tenant connection while tenancy is initialized. |
| `connections.tenant` | `PAYMENTS_TENANT_CONNECTION` | `null` | `PaymentDiscountCode`, `PaymentTaxRate`, `PaymentInvoice` (`UsesTenantConnection`), tenant migrations (`getConnection()`), `LocalInvoiceDriver` transaction | `null` means the default connection, which stancl/tenancy switches to the tenant database. |
| `queue` | `PAYMENTS_QUEUE` | `payments` | `ProcessWebhookEventJob` constructor (`onQueue`) | A worker must listen to this queue. |
| `webhook_path` | none | `payments/webhooks` | `routes/webhooks.php` | Route: `POST {webhook_path}/{provider}`, name `payments.webhooks`. Not an env var; publish the config to change it. |
| `stripe.secret` | `STRIPE_SECRET` | `null` | `StripeClient` singleton | Platform account secret key. `StripeAccountDriver::dashboardUrl()` checks whether it starts with `sk_test_`. |
| `stripe.webhook_secrets.snapshot` | `STRIPE_WEBHOOK_SECRET` | `null` | `StripeWebhookDriver` | Verifies non-thin events. |
| `stripe.webhook_secrets.thin` | `STRIPE_THIN_WEBHOOK_SECRET` | `null` | `StripeWebhookDriver` | Verifies events whose JSON has `"object": "v2.core.event"`. |
| `stripe.country` | `STRIPE_ACCOUNT_COUNTRY` | `nl` | `StripeAccountDriver` (`identity.country`), `StripeTaxRates` (tax rate `country` and `jurisdiction`, upper-cased) | One country for all new accounts. |
| `stripe.currency` | `STRIPE_ACCOUNT_CURRENCY` | `eur` | `StripeAccountDriver` (`defaults.currency`) | Checkout currency comes from `CreateCheckoutData::$currency`, not from here. |
| `local.invoice_number_prefix` | `PAYMENTS_INVOICE_PREFIX` | `INV` | `LocalInvoiceDriver` | Numbers look like `INV-2026-0001`. |
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
| `database/migrations/tenant` | **No** | `payment_discount_codes`, `payment_tax_rates`, `payment_invoices` on `connections.tenant` (via the migration's `getConnection()`) |

`loadMigrationsFrom()` does not descend into subdirectories, so the tenant path must be registered by the app.

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

Webhook processing runs in `ProcessWebhookEventJob` on `payments.queue`. The job sets no `$tries`, `$backoff` or timeout of its own; the worker's settings apply. See [Webhooks](Webhooks.md).
