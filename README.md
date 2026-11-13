# Laravel Payments

[![run-tests](https://github.com/net-os-com/laravel-payments/actions/workflows/run-tests.yml/badge.svg)](https://github.com/net-os-com/laravel-payments/actions/workflows/run-tests.yml)

`net-os/laravel-payments` is a PSP-agnostic payments layer for multi-tenant Laravel applications. It gives an application one API for connected merchant accounts, hosted checkouts, refunds, invoices, tax and discount codes, and lets each merchant choose per capability whether the payment provider or the application handles it.

Stripe Connect is the first (and currently only) provider. The package is extracted from Net OS, a workplace management platform, which is its reference host app.

> [!NOTE]
> **Version 0.x.** Until 1.0 the public API, config keys and database schema may change between minor versions. Not every capability has both drivers (see the [support matrix](#driver-support-matrix)); review the [known limitations](#known-limitations) before going to production.

## Contents

- [Why](#why)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration reference](#configuration-reference)
- [Core concepts](#core-concepts)
- [Quick start](#quick-start)
- [Contracts the host app implements](#contracts-the-host-app-implements)
- [Webhooks](#webhooks)
- [Events](#events)
- [Driver support matrix](#driver-support-matrix)
- [Known limitations](#known-limitations)
- [Documentation (wiki)](#documentation-wiki)
- [Testing](#testing)
- [License](#license)

## Why

A platform that lets many merchants (tenants) take payments needs the same flows for every merchant, but merchants differ in what they want the provider to do:

- Some want the provider to do everything: invoices, tax and promotion codes inside the provider's checkout.
- Some want the provider to only take the money, and keep invoicing, VAT and discount codes in the application.
- Some want a mix.

The package splits payments into **capabilities** (accounts, checkouts, refunds, invoicing, tax, discount codes, webhooks). Each capability has a **manager** with one or more **drivers**. Payment capabilities always run at the provider. Invoicing, tax and discount codes have a **source** (`stripe` or `local`) per merchant, and the package picks the driver for that source at call time.

Drivers keep no state: every call receives a `PaymentContext` that says whose money is handled. That keeps them safe in long-running workers (Octane, queue workers) and in multi-tenant apps where the merchant changes per request or job.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | `^8.4` |
| Laravel | 12 (`laravel/framework: ^12.0`) |
| `spatie/laravel-data` | `^4.0` |
| `lorisleiva/laravel-actions` | `^2.8` |
| `stripe/stripe-php` | `^22.1` (Accounts v2 and thin events) |
| `spatie/laravel-pdf` | `^2.0`, with a configured driver (e.g. Cloudflare), to render local invoices and credit notes |
| A queue worker | Processes inbound webhooks and renders local document PDFs on the `payments` queue (configurable) |
| Stripe | A platform account with Connect enabled |

Multi-tenancy is optional. The package was built for [`stancl/tenancy`](https://tenancyforlaravel.com) with a database per tenant, but it only knows about two database connections (`central` and `tenant`), which can both be the default connection in a single-database app.

## Installation

### 1. Require the package

The package is developed on GitHub. If it is not available from Packagist for your setup, add the repository first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/net-os-com/laravel-payments" }
    ]
}
```

```bash
composer require net-os/laravel-payments:@dev
```

The reference host app uses a Composer `path` repository with `"symlink": true` during development.

### 2. Service provider and facade

Both are auto-discovered through `extra.laravel` in `composer.json`:

| Registered | Class |
| --- | --- |
| Service provider | `NetOS\Payments\PaymentsServiceProvider` |
| Facade alias `Payments` | `NetOS\Payments\Facades\Payments` |

The service provider:

- merges `config/payments.php` under the `payments` key;
- binds the seven capability managers as singletons, so a driver added with `extend()` is the one the facade uses;
- binds `NetOS\Payments\Payments` as a singleton (the facade's root);
- binds `Stripe\StripeClient` as a singleton, built with `payments.stripe.secret`;
- loads the **central** migrations from `database/migrations` (not the `tenant` subdirectory);
- loads the webhook route `POST {webhook_path}/{provider}`;
- loads the signed document routes `GET {routes.documents_prefix}/{document}` and `GET {routes.documents_prefix}/{document}/pdf`;
- registers the views under the namespace `payments`;
- registers the `payments:retry-webhooks` command (in the console).

### 3. Publish the config, views and migrations (optional)

```bash
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-views
php artisan vendor:publish --tag=payments-migrations
```

`payments-config` copies `config/payments.php` to your app; `payments-views` copies the invoice and credit note view to `resources/views/vendor/payments`; `payments-migrations` copies `database/migrations`, including the `tenant` subdirectory, to your app's `database/migrations`. The central migrations are also loaded from the package without publishing.

### 4. Migrations: central vs tenant

The package has two groups of migrations:

| Group | Path in the package | Tables | Connection |
| --- | --- | --- | --- |
| Central | `database/migrations` | `payment_accounts`, `payment_webhook_events` | `payments.connections.central` |
| Tenant | `database/migrations/tenant` | `payment_discount_codes`, `payment_tax_rates`, `payment_invoices`, `payment_document_sequences` | `payments.connections.tenant` |

The central migrations are loaded automatically and run with `php artisan migrate`.

The tenant migrations are **not** loaded automatically. Register their path wherever your app runs tenant migrations. Alternatively, publish them with the `payments-migrations` tag; they then land in your app's `database/migrations/tenant`.

**stancl/tenancy (database per tenant):** add the path to `migration_parameters` in `config/tenancy.php`:

```php
'migration_parameters' => [
    '--force' => true,
    '--path' => [
        database_path('migrations/tenant'),
        base_path('vendor/net-os/laravel-payments/database/migrations/tenant'),
    ],
    '--realpath' => true,
],
```

Then `php artisan tenants:migrate` creates the tenant tables in every tenant database.

**Single database:** load the tenant path as well, for example in a service provider:

```php
$this->loadMigrationsFrom(base_path('vendor/net-os/laravel-payments/database/migrations/tenant'));
```

### 5. Bind the contracts

The app must bind `ResolvesPaymentContext` and, for local invoicing, `ProvidesIssuer`. See [Contracts the host app implements](#contracts-the-host-app-implements).

### 6. Run a queue worker for `payments`

Webhooks are stored by the controller and processed by `ProcessWebhookEventJob` on the queue from `payments.queue` (default `payments`). `RenderInvoicePdfJob` renders local invoices and credit notes on the same queue. Make sure a worker listens to it, for example `php artisan queue:work --queue=payments,default`.

### 7. Local invoicing: PDF renderer, disk and mail

For local invoicing, configure `spatie/laravel-pdf` with a driver (`LARAVEL_PDF_DRIVER`, e.g. Cloudflare and its credentials), set `payments.local.invoice_disk` to a disk both web and queue workers reach, add the middleware that restores your app's context to `payments.routes.documents_middleware` (and the matching `PaymentContext::$urlParameters`), and listen to `PaymentInvoiceIssued` to mail documents. See [Invoices](docs/Invoices.md).

## Configuration reference

All keys live in `config/payments.php`.

| Key | Env var | Default | Purpose |
| --- | --- | --- | --- |
| `connections.central` | `PAYMENTS_CENTRAL_CONNECTION` | `null` | Connection of `PaymentAccount` and `PaymentWebhookEvent` and of the central migrations. `null` means the default connection. |
| `connections.tenant` | `PAYMENTS_TENANT_CONNECTION` | `null` | Connection of `PaymentDiscountCode`, `PaymentTaxRate`, `PaymentInvoice` and `PaymentDocumentSequence` and of the tenant migrations. `null` means the default connection, which with stancl/tenancy is the current tenant's database. |
| `queue` | `PAYMENTS_QUEUE` | `payments` | Queue of `ProcessWebhookEventJob` and `RenderInvoicePdfJob`. |
| `webhook_path` | (none) | `payments/webhooks` | Prefix of the webhook route; the route is `POST {webhook_path}/{provider}`. |
| `routes.documents_prefix` | (none) | `payments/documents` | Prefix of the signed document routes `GET {prefix}/{document}` (`payments.documents.show`) and `GET {prefix}/{document}/pdf` (`payments.documents.pdf`). |
| `routes.documents_middleware` | (none) | `[]` | Middleware added after `signed:relative` on the document routes, e.g. the tenancy middleware. |
| `stripe.secret` | `STRIPE_SECRET` | `null` | Secret key of the Stripe **platform** account. |
| `stripe.webhook_secrets.snapshot` | `STRIPE_WEBHOOK_SECRET` | `null` | Signing secret of the event destination for snapshot (v1) events of connected accounts. |
| `stripe.webhook_secrets.thin` | `STRIPE_THIN_WEBHOOK_SECRET` | `null` | Signing secret of the event destination for thin (v2) events. |
| `stripe.country` | `STRIPE_ACCOUNT_COUNTRY` | `nl` | Country of new connected accounts, and country of inclusive tax rates the checkout creates. |
| `stripe.currency` | `STRIPE_ACCOUNT_CURRENCY` | `eur` | Default currency of new connected accounts. |
| `local.invoice_number_prefix` | `PAYMENTS_INVOICE_PREFIX` | `INV` | Series of local invoice numbers: `{prefix}-{year}-0001`. |
| `local.credit_note_number_prefix` | `PAYMENTS_CREDIT_NOTE_PREFIX` | `CN` | Series of local credit note numbers: `{prefix}-{year}-0001`. |
| `local.invoice_disk` | `PAYMENTS_INVOICE_DISK` | `local` | Disk the PDFs of local documents are stored on; web and queue workers must both reach it. |
| `local.tax_country` | `PAYMENTS_TAX_COUNTRY` | `nl` | Country whose rates `LocalTaxDriver` reads from `payment_tax_rates`. |

A published config may contain extra app-specific keys (the reference host app adds `account_defaults`); the package itself does not read them.

## Core concepts

The short version; the [wiki](#documentation-wiki) has the details.

### PaymentContext

`NetOS\Payments\PaymentContext` is an immutable value object passed into every call:

```php
final readonly class PaymentContext
{
    public function __construct(
        public Provider $provider,               // Provider::STRIPE
        public ?string $providerAccountId,       // the merchant's account, null before connecting
        public CapabilitySourcesData $sources,   // invoicing, tax, discounts: stripe|local
        public ?IssuerData $issuer = null,       // legal details for local invoices
        public array $urlParameters = [],        // added to signed document links, e.g. ['tenant' => '...']
    ) {}
}
```

Helpers: `PaymentContext::forAccount($provider, $accountId)`, `withAccount()`, `withSources()`, `withDiscountSource()`, `withInvoicingSource()` and `requireAccount()` (throws `AccountNotConnectedException` when there is no account).

### Capabilities, managers and drivers

| Capability | Facade method | Contract | Driver picked by |
| --- | --- | --- | --- |
| Accounts | `Payments::accounts($context)` | `ManagesAccounts` | `$context->provider` |
| Checkouts | `Payments::checkouts($context)` | `ProcessesCheckouts` | `$context->provider` |
| Refunds | `Payments::refunds($context)` | `IssuesRefunds` | `$context->provider` |
| Invoices | `Payments::invoices($context)` | `ProvidesInvoices` | `$context->sources->invoicing` |
| Tax | `Payments::tax($context)` | `CalculatesTax` | `$context->sources->tax` |
| Discount codes | `Payments::discounts($context)` | `ManagesDiscountCodes` | `$context->sources->discounts` |
| Webhooks | `Payments::webhooks($provider)` | `TranslatesWebhooks` | the `{provider}` route segment |

Each manager extends `Illuminate\Support\Manager`, so drivers are created once per manager and can be added with `extend()`.

### Capability sources and profiles

`CapabilitySource` is `stripe` or `local`. `CapabilitySourcesData` holds one source for invoicing, tax and discounts.

**Rule:** local invoicing requires local tax **and** local discount codes, because a local invoice cannot depend on amounts the provider calculates inside its checkout. `CapabilitySourcesData::problem()` returns the reason, `assertAllowed()` throws an `InvalidArgumentException`.

`PaymentProfile` offers three presets:

| Profile | Invoicing | Tax | Discounts |
| --- | --- | --- | --- |
| `STRIPE_MANAGED` | stripe | stripe | stripe |
| `STRIPE_WITH_LOCAL_DISCOUNTS` | stripe | stripe | local |
| `LOCALLY_MANAGED` | local | local | local |

Any other combination that passes `assertAllowed()` is valid too (for example Stripe invoicing with local tax and Stripe codes).

### `isHandledByCheckout()`

Invoices, tax and discount drivers say whether the provider handles the capability inside its checkout. When it does, the app must not call it directly: `StripeTaxDriver::calculate()` and `StripeInvoiceDriver::issue()` throw `HandledByCheckoutException`.

## Quick start

All examples use the facade. Inject `ResolvesPaymentContext` (or call `app(ResolvesPaymentContext::class)`) to get the current context.

### Connect a merchant account (Stripe Connect, Accounts v2)

```php
use NetOS\Payments\Actions\SyncPaymentAccountAction;
use NetOS\Payments\Contracts\ResolvesPaymentContext;
use NetOS\Payments\Data\AccountDefaultsData;
use NetOS\Payments\Data\BrandingData;
use NetOS\Payments\Data\CreateAccountData;
use NetOS\Payments\Enums\PaymentAccountStatus;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentAccount;
use NetOS\Payments\PaymentContext;

$context = app(ResolvesPaymentContext::class)->current();

$data = Payments::accounts($context)->create($context, new CreateAccountData(
    displayName: $merchant->name,
    email: $user->email,
    metadata: ['merchant_id' => (string) $merchant->getKey()],
    defaults: new AccountDefaultsData(
        statementDescriptor: 'ACME DESKS',      // only writable before onboarding
        supportEmail: $user->email,              // only writable before onboarding
        locales: ['en-US'],
        branding: new BrandingData(primaryColor: '#0A2D51'),
    ),
));

// The package stores nothing by itself: the app creates the local record.
$account = PaymentAccount::query()->create([
    'owner_type' => $merchant->getMorphClass(),
    'owner_id' => (string) $merchant->getKey(),
    'provider' => Provider::STRIPE,
    'provider_account_id' => $data->providerAccountId,
    'status' => PaymentAccountStatus::ONBOARDING,
]);
SyncPaymentAccountAction::make()->handle($account, $data);

// Send the account holder to Stripe's hosted onboarding.
$context = PaymentContext::forAccount(Provider::STRIPE, $account->provider_account_id);
$url = Payments::accounts($context)->onboardingUrl(
    $context,
    returnUrl: 'https://app.example.com/settings/payments',
    refreshUrl: 'https://api.example.com/payments/onboarding/refresh', // must mint a new link
);
```

### Create a checkout

```php
use Carbon\CarbonImmutable;
use NetOS\Payments\Data\CheckoutLineItemData;
use NetOS\Payments\Data\CreateCheckoutData;
use NetOS\Payments\Facades\Payments;

$context = app(ResolvesPaymentContext::class)->current();

$checkout = Payments::checkouts($context)->create($context, new CreateCheckoutData(
    lineItems: [
        new CheckoutLineItemData(
            description: 'Desk 4.12, Mon 9 Nov 09:00 - 12:00',
            unitAmount: 2400,          // minor units, incl. tax
            quantity: 1,
            taxPercentage: 21.0,       // required when tax is not stripe
        ),
    ],
    currency: 'eur',
    customerEmail: 'guest@example.com',
    successUrl: 'https://app.example.com/pay/'.$payment->uuid,
    cancelUrl: 'https://app.example.com/pay/'.$payment->uuid.'?cancelled=1',
    expiresAt: CarbonImmutable::now()->addMinutes(31),   // 30 minutes to 24 hours ahead
    providerDiscountId: null,          // a Stripe promotion code id, when discounts are stripe
    description: 'Desk booking',
    invoiceFooter: 'Thank you for booking.',
    metadata: ['payment_uuid' => $payment->uuid],
));

return redirect()->away($checkout->url);
```

Later, `fetch()` returns the current state and `expire()` ends an open checkout:

```php
$checkout = Payments::checkouts($context)->fetch($context, $checkoutId);   // CheckoutStatus::OPEN|PAID|EXPIRED
$checkout = Payments::checkouts($context)->expire($context, $checkoutId);
```

### Validate and redeem a discount code

```php
$discounts = Payments::discounts($context);
$result = $discounts->validate($context, $typedCode, $payment->uuid);   // DiscountValidationData

if (! $result->isValid) {
    return back()->withErrors(['code' => $result->reason]);
}

// Hold a one-time code for this checkout until it expires (a no-op for Stripe codes).
if (! $discounts->reserve($context, $result->discountCode, $payment->uuid, $expiresAt)) {
    return back()->withErrors(['code' => 'This code is already in use.']);
}

if ($discounts->isHandledByCheckout()) {
    // Stripe source: pass the promotion code to the checkout.
    $providerDiscountId = $result->discountCode->provider_discount_id;
} else {
    // Local source: lower the line items yourself by $result->discountCode->percent_off.
}

// After the checkout is paid (ends the hold):
$discounts->redeem($context, $result->discountCode);

// When the checkout expires or is cancelled instead:
$discounts->release($context, $result->discountCode, $payment->uuid);
```

Create a code:

```php
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Enums\DiscountCodeType;

$code = Payments::discounts($context)->create($context, new CreateDiscountCodeData(
    type: DiscountCodeType::ONE_TIME,
    name: 'Welcome',
    description: null,
    code: 'welcome-2026',              // stored in capitals
    percentOff: 20,
    validFrom: CarbonImmutable::today(),
    validUntil: null,                  // inclusive; null = no end date
    createdBy: $user->id,
));
```

### Refund

```php
$refund = Payments::refunds($context)->refund($context, $checkout->providerPaymentId);          // full
$refund = Payments::refunds($context)->refund($context, $checkout->providerPaymentId, 1000);    // partial, minor units
// ProviderRefundData: providerRefundId, amount, status
```

### Invoices

```php
use NetOS\Payments\Data\IssueInvoiceData;
use NetOS\Payments\Data\TaxableLineData;

$invoices = Payments::invoices($context);

if ($invoices->isHandledByCheckout()) {
    // Stripe source: Stripe created the invoice in Checkout; read it.
    $invoice = $invoices->find($context, $checkout->providerInvoiceId);
} else {
    // Local source: issue it yourself after the payment. The PDF is rendered on the queue.
    $lines = Payments::tax($context)->calculate($context, [
        new TaxableLineData('Desk booking', 2400, 'workspace_rental'),
    ]);
    $invoice = $invoices->issue($context, new IssueInvoiceData(
        payerName: 'Guest Name',
        payerEmail: 'guest@example.com',
        lines: $lines,
        discountAmount: 0,
        currency: 'eur',
        reference: $payment->uuid,
        customer: $checkout->customer,   // billing details from Stripe Checkout
    ));
}

// After a refund, for both sources; returns the credit note (type credit_note).
$creditNote = $invoices->creditNote($context, $invoice->reference, $refund->amount, $refund->providerRefundId);
```

Stripe Checkout always asks for the billing address and tax id (`customer_creation: always`, `billing_address_collection: required`, `tax_id_collection`), and `ProviderCheckoutData::$customer` returns them as `CustomerDetailsData`. A local invoice is numbered gaplessly per series and year (`INV-2026-0001`, credit notes `CN-2026-0001`), served as a hosted page and PDF on signed links, and announced with `PaymentInvoiceIssued` once its PDF is stored. The app mails it.

## Contracts the host app implements

| Contract | Method | Purpose |
| --- | --- | --- |
| `NetOS\Payments\Contracts\ResolvesPaymentContext` | `current(): PaymentContext` | The context of the current account holder (current tenant, team, user, ...). |
| `NetOS\Payments\Contracts\ProvidesIssuer` | `issuer(): IssuerData` | The merchant's legal details printed on local invoices. Needed only when invoicing is local; `LocalInvoiceDriver::issue()` throws without an issuer in the context. |

The package itself never resolves these contracts; the app uses them to build contexts and to pass them to the facade. Binding them gives the app one place to do so.

```php
// AppServiceProvider::register()
$this->app->bind(ResolvesPaymentContext::class, TenantPaymentContextResolver::class);
$this->app->bind(ProvidesIssuer::class, TenantIssuerProvider::class);
```

```php
use NetOS\Payments\Contracts\ProvidesIssuer;
use NetOS\Payments\Contracts\ResolvesPaymentContext;
use NetOS\Payments\Data\CapabilitySourcesData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Models\PaymentAccount;
use NetOS\Payments\PaymentContext;

class TenantPaymentContextResolver implements ResolvesPaymentContext
{
    public function __construct(private readonly ProvidesIssuer $issuer) {}

    public function current(): PaymentContext
    {
        $tenant = tenant();
        $account = PaymentAccount::query()->forOwner($tenant)->current()->first();
        $settings = $tenant->payment_settings ?? [];

        return new PaymentContext(
            provider: $account->provider ?? Provider::STRIPE,
            providerAccountId: $account?->provider_account_id,
            sources: (new CapabilitySourcesData(
                invoicing: CapabilitySource::tryFrom($settings['invoicing'] ?? '') ?? CapabilitySource::STRIPE,
                tax: CapabilitySource::tryFrom($settings['tax'] ?? '') ?? CapabilitySource::STRIPE,
                discounts: CapabilitySource::tryFrom($settings['discounts'] ?? '') ?? CapabilitySource::STRIPE,
            ))->assertAllowed(),
            issuer: $this->issuer->issuer(),
            urlParameters: ['tenant' => (string) $tenant->getTenantKey()],   // read by documents_middleware
        );
    }
}
```

The [host app integration guide](docs/Host-App-Integration.md) walks through the full reference implementation.

## Webhooks

### Route

The package registers `POST /{webhook_path}/{provider}`, named `payments.webhooks`. With the defaults that is:

```
POST https://your-app.example.com/payments/webhooks/stripe
```

The route is loaded without a middleware group (no session, no CSRF, no throttle). An unknown `{provider}` returns 404, a bad signature or payload returns 400, and an accepted event returns 204.

### What happens

1. `StripeWebhookDriver::translate()` verifies the signature and maps the request to `ProviderEventData`.
2. The controller stores it in `payment_webhook_events` (unique per provider and event id) and queues it while it is unprocessed. A duplicate delivery of an already processed event is acknowledged and not processed again; a redelivery of an event that was never processed is queued again.
3. `ProcessWebhookEventJob` runs on the `payments` queue:
   - `checkout.session.*` events dispatch `PaymentCheckoutUpdated`;
   - `v2.core.account*` events re-sync the matching `PaymentAccount` with `SyncPaymentAccountAction`, which dispatches `PaymentAccountUpdated`;
   - events without a connected account, and all other types, are stored and skipped;
   - a failing event is tried up to 5 times (backoff 10, 60, 300 and 900 seconds).

`php artisan payments:retry-webhooks --older-than=10` queues stored events that are still unprocessed after the given number of minutes (default 10). It is safe to run on a schedule.

The job does not initialize tenancy. Listeners look up the `PaymentAccount` by provider account id and switch to its owner themselves.

### Stripe event destinations

Create two event destinations in the Stripe dashboard (Developers, Webhooks), both pointing at the route above:

| Destination | Events from | Payload style | Events to select | Secret goes in |
| --- | --- | --- | --- | --- |
| Connected accounts | Connected accounts | Snapshot | `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` | `STRIPE_WEBHOOK_SECRET` |
| Accounts v2 | Your account | Thin | `v2.core.account.updated` and the `v2.core.account[...]` events you need (e.g. requirements and capability status updates) | `STRIPE_THIN_WEBHOOK_SECRET` |

Both payload styles share one route: the driver recognizes a thin event by `"object": "v2.core.event"` and checks it against the thin secret; everything else is checked against the snapshot secret.

### Local development with the Stripe CLI

```bash
# Snapshot events of connected accounts
stripe listen --forward-connect-to http://localhost:8000/payments/webhooks/stripe \
  --events checkout.session.completed,checkout.session.expired

# Thin (v2) events
stripe listen --forward-thin-to http://localhost:8000/payments/webhooks/stripe \
  --thin-events 'v2.core.account.updated'
```

Each `stripe listen` session prints a `whsec_...` signing secret; put it in the matching env var. Flag names depend on your CLI version, check `stripe listen --help`.

See [Webhooks](docs/Webhooks.md) for the full flow.

## Events

| Event | Dispatched by | Properties | Use it to |
| --- | --- | --- | --- |
| `NetOS\Payments\Events\PaymentCheckoutUpdated` | `ProcessWebhookEventJob`, for every `checkout.session.*` event of a connected account | `Provider $provider`, `string $providerAccountId`, `string $providerCheckoutId` | Fetch the checkout and update whatever was being paid for (the package does not know that). |
| `NetOS\Payments\Events\PaymentAccountUpdated` | `SyncPaymentAccountAction`, on every sync (webhook or app-initiated) | `PaymentAccount $account` | React to status changes (e.g. notify the merchant when onboarding completes). |
| `NetOS\Payments\Events\PaymentInvoiceIssued` | `RenderInvoicePdfJob`, after the PDF of a local invoice or credit note is stored; `StripeInvoiceDriver::creditNote()`, right after it created a Stripe credit note | `InvoiceData $document`, `string $reference`, `?string $payerEmail`, `?string $pdfDisk`, `?string $pdfPath` | Mail the document to the payer. The package sends no mail; Stripe invoices are emailed by Stripe and do not fire it. |

The events are dispatched synchronously inside the queue job (or wherever the sync or credit note runs). Make a listener `ShouldQueue` if it does slow work.

```php
use NetOS\Payments\Events\PaymentCheckoutUpdated;
use NetOS\Payments\Models\PaymentAccount;

class SyncPaymentOnCheckoutUpdated
{
    public function handle(PaymentCheckoutUpdated $event): void
    {
        $account = PaymentAccount::query()
            ->where('provider', $event->provider)
            ->where('provider_account_id', $event->providerAccountId)
            ->first();

        // With stancl/tenancy: $account->owner->run(fn () => ...);
    }
}
```

## Driver support matrix

| Capability | `stripe` driver | `local` driver |
| --- | --- | --- |
| Accounts | **Real.** Accounts v2, merchant configuration, full dashboard; create, retrieve, onboarding link, configuration, branding (incl. logo upload), locales, dashboard URL. | Not available. |
| Checkouts | **Real.** Stripe Checkout as a direct charge on the connected account; create, fetch, expire. | Not available. |
| Refunds | **Real.** Full or partial refund of a PaymentIntent. | Not available. |
| Invoicing | **Real.** Stripe creates, renders and emails the invoice inside Checkout (`invoice_creation`); `find()` reads it. `issue()` throws `HandledByCheckoutException`. `creditNote()` creates a Stripe credit note linked to the refund and dispatches `PaymentInvoiceIssued`. | **Real.** Invoices and credit notes as separate documents in `payment_invoices`, numbered without gaps per series and year; PDF rendered with spatie/laravel-pdf on the queue; hosted page and PDF on signed routes; `PaymentInvoiceIssued` for the app to mail. Statuses `issued` → `partially_credited` → `credited`, like Stripe. |
| Tax | **Real, inside Checkout.** Stripe Tax (`automatic_tax`); `calculate()` throws `HandledByCheckoutException`. The connected account needs tax registrations in Stripe. | **Real.** Inclusive VAT per line from `payment_tax_rates`. Stripe receives the result as inclusive tax rates on the line items. |
| Discount codes | **Real.** Coupon + promotion code on the connected account, mirrored in `payment_discount_codes`. `reserve()` / `release()` are no-ops; Stripe enforces `max_redemptions`. | **Real.** Codes only in `payment_discount_codes`; the app lowers the line items. One-time codes can be reserved for one open checkout. |
| Webhooks | **Real.** Snapshot and thin events on one route, signature verification. | Not applicable. |

Only `Provider::STRIPE` exists. See [Extending](docs/Extending.md) to add a provider or driver.

## Known limitations

A short list; see [Security notes and known limitations](docs/Security-and-Limitations.md) for all of them with file references.

- Before 1.0 the API may change between minor versions. The tests use a fake of Stripe's API, not Stripe itself.
- Only Stripe. Accounts, checkouts and refunds have no local driver.
- The package sends no invoice or credit note mail; the app listens to `PaymentInvoiceIssued`.
- Local document links are signed without expiry. PDFs are rendered by the spatie/laravel-pdf driver you configure (with Cloudflare, the document HTML including the payer's personal data goes to Cloudflare) and stored on `payments.local.invoice_disk` under a path with a random suffix.
- One-time local codes are only protected against concurrent use when the app calls `reserve()`.
- Stripe promotion codes have no start date: a scheduled Stripe code is only blocked by the app's own `validate()`. Stripe Checkout's own code field is never offered.
- Discount code validation is not rate limited by the package; the app must throttle the endpoint that calls `validate()`. Codes need enough entropy.
- The package does not store `PaymentAccount` rows on `create()`; the app does.

## Documentation (wiki)

The `docs/` directory holds the full documentation, written to be copied to the GitHub wiki.

- [Home](docs/Home.md)
- [Architecture](docs/Architecture.md)
- [Configuration](docs/Configuration.md)
- [Payment Context](docs/Payment-Context.md)
- [Accounts and Onboarding](docs/Accounts-and-Onboarding.md)
- [Checkout](docs/Checkout.md)
- [Tax](docs/Tax.md)
- [Discount Codes](docs/Discount-Codes.md)
- [Invoices](docs/Invoices.md)
- [Refunds](docs/Refunds.md)
- [Webhooks](docs/Webhooks.md)
- [Data Model](docs/Data-Model.md)
- [Extending](docs/Extending.md)
- [Host App Integration](docs/Host-App-Integration.md)
- [Security and Limitations](docs/Security-and-Limitations.md)
- [Testing](docs/Testing.md)
- [Glossary](docs/Glossary.md)

## Testing

The test suite uses Pest and Orchestra Testbench with an in-memory SQLite database. Stripe is replaced by a fake HTTP client that records every request (`tests/Support/FakeStripe.php`), so no network or keys are needed.

```bash
composer install
composer test
```

GitHub Actions runs the suite on pushes and pull requests that change code (PHP 8.4 and 8.5, Laravel 12). See [Testing](docs/Testing.md).

## License

MIT. See [LICENSE](LICENSE).
