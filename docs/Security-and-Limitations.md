# Security notes and known limitations

[Home](Home.md) › Security and Limitations

Until 1.0 the API may change between minor versions. This page lists what the package does not do and what the host app is responsible for. File references are relative to the repository root.

## Status

- **Tests**: a Pest suite with a recorded fake of Stripe's API runs in GitHub Actions; it does not call Stripe itself. See [Testing](Testing.md).
- **API may change**: class names, DTO fields, config keys and migrations can change without a deprecation path.
- **One provider**: only Stripe. Accounts, checkouts and refunds have no other driver.
- **No mail**: the package sends no invoice or credit note mail itself; the host app listens to `PaymentInvoiceIssued` and sends it.

## Security notes

### Webhooks

- Signatures are verified for both payload styles with Stripe's SDK and the default tolerance of 300 seconds (`src/Drivers/Stripe/StripeWebhookDriver.php`). Keep both signing secrets out of version control.
- The route has no middleware group (`routes/webhooks.php`): no throttle and no CSRF. That is normal for webhooks, but add rate limiting at the edge if the endpoint is exposed to abuse. In a multi-domain tenancy setup the route answers on every domain the app serves.
- Every verified event is stored, including types the package ignores. `payment_webhook_events.payload` can contain customer names, emails and addresses from Stripe objects. The package never prunes it; define a retention policy in the app.
- Processing happens on a queue without tenancy. Listeners must derive the tenant from `PaymentAccount` (central) and must not trust ids in event metadata for authorization.

### Discount codes

- `validate()` reveals whether a code exists and why it is invalid. **Rate limiting is the host app's job**: throttle every endpoint that calls it (quotes, bookings, checkout creation).
- **Codes must have enough entropy** if they are not meant to be public. A short code like `SUMMER` is guessable through any validation endpoint.
- Validation and redemption are separate. A local `ONE_TIME` code is only protected against two concurrent checkouts when the app calls `reserve()` before the checkout and passes the checkout's reference to `validate()` (`src/Drivers/Local/LocalDiscountCodeDriver.php`). The package does not call `reserve()` by itself. Stripe enforces `max_redemptions` for Stripe codes.
- A Stripe code's `valid_from` is not known to Stripe; it is only stored on the local row. Stripe Checkout therefore never offers Stripe's own code field (`src/Drivers/Stripe/StripeCheckoutDriver.php`), and a code is only applied through `providerDiscountId`. The start date is enforced by `validate()` alone, so only pass a `providerDiscountId` of a code that `validate()` accepted.

### Documents and PDFs

- **Document links are signed without expiry** (`src/Support/LocalDocumentPresenter.php`). Anyone holding a `hostedUrl` or `pdfUrl` can open the invoice or credit note, with the payer's name, email, company, address and VAT id, for as long as the app's key does not change. The links cannot be revoked one by one; rotating `APP_KEY` invalidates all of them. The routes have no authentication of their own, only `signed` plus `payments.routes.documents_middleware`.
- **PDF rendering needs `spatie/laravel-pdf` with a configured driver.** `RenderInvoicePdfJob` uses whatever driver that package is configured with (`src/Jobs/RenderInvoicePdfJob.php`). With a remote renderer such as Cloudflare, the full document HTML, including the payer's personal data, is sent to that service. Treat it as a processor of personal data. Without a working driver the job fails (3 tries) and no `PaymentInvoiceIssued` is dispatched.
- **PDFs are stored on `payments.local.invoice_disk`** (default `local`) at `invoices/{year}/{number}-{random}.pdf`. The 16 random characters keep paths unique on a shared disk; they are not access control. Protect the disk itself (private visibility), and make sure the web and queue workers reach the same disk. The package never deletes PDFs.
- The PDF route streams the file from the disk stored on the row (`pdf_disk`), so changing `invoice_disk` later does not break existing links.

### Accounts

- `BrandingData::$logoPath` must be a readable local file (`src/Drivers/Stripe/StripeAccountDriver.php`). URLs and stream wrappers (anything like `scheme://`) are rejected with a `RuntimeException`, so the driver cannot be used to fetch remote locations. Still never pass a path from user input: any readable local file would be uploaded to Stripe.
- The onboarding `refreshUrl` must point at an endpoint that only authorized callers can reach (the reference app signs it), because it mints a fresh onboarding link for the account.
- The package has **no authorization** layer. Anything that can call the facade with a context can create checkouts, refunds and codes on that account. Authorize in the app before calling it.

### Money handling

- All amounts are integers in minor units, tax-inclusive. The package does not validate amounts, currencies or percentages; Stripe rejects some invalid values, the local drivers do not. `StripeCheckoutDriver::create()` does check two things before calling Stripe: `expiresAt` must be 30 minutes to 24 hours ahead, and every line needs a `taxPercentage` when tax is not `stripe` (both throw `InvalidArgumentException`).
- Checkouts are direct charges on the connected account. The account itself is created with `fees_collector` and `losses_collector` set to `stripe` (`defaults.responsibilities`). The platform takes no application fee in this version.

### Document links and tenancy

Document links are signed on path and query only (`signed:relative`), so they survive proxies that change scheme or host. The tenant must therefore come from a signed query parameter: a tenancy middleware that also reads a header (stancl's `InitializeTenancyByRequestData` reads `X-Tenant` first) would let a valid link be replayed against another tenant's document with the same id. Use a middleware that only reads the query parameter.

## Known limitations

| Area | Limitation |
| --- | --- |
| Installation | Tenant migrations are not auto-loaded; `loadMigrationsFrom()` only loads `database/migrations` (`src/PaymentsServiceProvider.php`). Publish them with the `payments-migrations` tag or point the tenancy package at `database/migrations/tenant` in the package. |
| Dependencies | `spatie/laravel-pdf` (`^2.0`) is required, and it needs a renderer of its own. |
| Accounts | `create()` does not persist a `PaymentAccount`; the app must. No method to update the display name, statement descriptor or support details after creation. No disconnect method. |
| Accounts | One country and default currency for all accounts (`payments.stripe.country`, `.currency`). |
| Checkout | `mode: payment` only (no subscriptions, no setup). No application fees, no `on_behalf_of`, no payment method configuration. |
| Tax | One tax country per app (`payments.local.tax_country`). Rate chosen for today, not the supply date (`src/Drivers/Local/LocalTaxDriver.php:47`). |
| Discounts | Percentage discounts only. |
| Discounts | Reservations expire only by time: a hold whose checkout ended without `release()` blocks the code until `reserved_until`. |
| Invoices | `issue()` and `creditNote()` of the local driver return `pdfUrl = null`; the PDF is rendered on the queue. Call `find()` later for the link. |
| Invoices | The year of a local document number is the year of `now()` in the app's timezone. |
| Invoices | Stripe credit notes have no `hostedUrl`. `PaymentInvoiceIssued` for a Stripe credit note uses the invoice's `metadata['payment_uuid']` as reference, a key the host app has to set; without it the reference falls back to the Stripe invoice id (`src/Drivers/Stripe/StripeInvoiceDriver.php`). |
| Invoices | The document view has fixed English labels and formats amounts with a comma as decimal separator; publish `payments-views` to change it. |
| Refunds | No refund webhooks; a `pending` refund that later fails is not reported. A refunded one-time code stays used. |
| Webhooks | No pruning of `payment_webhook_events`. Events that still fail after the job's 5 tries are only retried by `payments:retry-webhooks` or a redelivery. |
| Multi-tenancy | Assumes the app owns tenancy. The package has no notion of tenants beyond the two connections. |

## Responsibilities of the host app

| Concern | Package | Host app |
| --- | --- | --- |
| Who the merchant is | | `ResolvesPaymentContext` |
| Merchant's legal details | | `ProvidesIssuer` |
| Storing source choices | validation rule only | storage, UI |
| Persisting accounts | `SyncPaymentAccountAction` updates rows | creating rows, disconnect, reconnect |
| What is being paid for | | own payment records |
| Applying local discounts to amounts | | yes |
| Reserving one-time codes during a checkout | `reserve()`, `release()` | calling them |
| Delivering invoices and credit notes | `PaymentInvoiceIssued` | sending the mail |
| PDF renderer and disk | `RenderInvoicePdfJob` | spatie/laravel-pdf driver, `invoice_disk` |
| Tenant context of document links | `PaymentContext::$urlParameters` | filling them, `documents_middleware` |
| Idempotent payment state changes | dedup of webhook events | row locks, status checks |
| Tenancy for webhook listeners | | yes |
| Authorization | | yes |
| Rate limiting of code validation | | yes |
| Tax rate seeding | | yes |
| Retention of webhook payloads | | yes |
