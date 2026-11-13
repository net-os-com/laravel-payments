# Security notes and known limitations

[Home](Home.md) › Security and Limitations

The package is a proof of concept. This page lists what it does not do, what the host app is responsible for, and places where the code looks incomplete or wrong. File references are relative to the repository root.

## Status

- **No automated tests** and no CI in this repository.
- **API may change**: class names, DTO fields, config keys and migrations can change without a deprecation path.
- **One provider**: only Stripe. Accounts, checkouts and refunds have no other driver.
- **No mail**: the package sends no invoice or credit note mail itself; the host app listens to `PaymentInvoiceIssued` and sends it.

## Security notes

### Webhooks

- Signatures are verified for both payload styles with Stripe's SDK and the default tolerance of 300 seconds (`src/Drivers/Stripe/StripeWebhookDriver.php`). Keep both signing secrets out of version control.
- The route has no middleware group (`src/PaymentsServiceProvider.php:24`): no throttle and no CSRF. That is normal for webhooks, but add rate limiting at the edge if the endpoint is exposed to abuse. In a multi-domain tenancy setup the route answers on every domain the app serves.
- Every verified event is stored, including types the package ignores. `payment_webhook_events.payload` can contain customer names, emails and addresses from Stripe objects. The package never prunes it; define a retention policy in the app.
- Processing happens on a queue without tenancy. Listeners must derive the tenant from `PaymentAccount` (central) and must not trust ids in event metadata for authorization.

### Discount codes

- `validate()` reveals whether a code exists and why it is invalid. **Rate limiting is the host app's job**: throttle every endpoint that calls it (quotes, bookings, checkout creation).
- **Codes must have enough entropy** if they are not meant to be public. A short code like `SUMMER` is guessable through any validation endpoint.
- Validation and redemption are separate. A local `ONE_TIME` code is only protected against two concurrent checkouts when the app calls `reserve()` before the checkout and passes the checkout's reference to `validate()` (`src/Drivers/Local/LocalDiscountCodeDriver.php`). The package does not call `reserve()` by itself. Stripe enforces `max_redemptions` for Stripe codes.
- A Stripe code's `valid_from` is not known to Stripe (`src/Drivers/Stripe/StripeDiscountCodeDriver.php:41-55`). With `allow_promotion_codes` (no `providerDiscountId` given), customers can apply a scheduled code in Stripe Checkout before its start date.

### Documents and PDFs

- **Document links are signed without expiry** (`src/Support/LocalDocumentPresenter.php`). Anyone holding a `hostedUrl` or `pdfUrl` can open the invoice or credit note, with the payer's name, email, company, address and VAT id, for as long as the app's key does not change. The links cannot be revoked one by one; rotating `APP_KEY` invalidates all of them. The routes have no authentication of their own, only `signed` plus `payments.routes.documents_middleware`.
- **PDF rendering needs `spatie/laravel-pdf` with a configured driver.** `RenderInvoicePdfJob` uses whatever driver that package is configured with (`src/Jobs/RenderInvoicePdfJob.php`). With a remote renderer such as Cloudflare, the full document HTML, including the payer's personal data, is sent to that service. Treat it as a processor of personal data. Without a working driver the job fails (3 tries) and no `PaymentInvoiceIssued` is dispatched.
- **PDFs are stored on `payments.local.invoice_disk`** (default `local`) at `invoices/{year}/{number}-{random}.pdf`. The 16 random characters keep paths unique on a shared disk; they are not access control. Protect the disk itself (private visibility), and make sure the web and queue workers reach the same disk. The package never deletes PDFs.
- The PDF route streams the file from the disk stored on the row (`pdf_disk`), so changing `invoice_disk` later does not break existing links.

### Accounts

- `BrandingData::$logoPath` is read with `file_get_contents()` (`src/Drivers/Stripe/StripeAccountDriver.php:166`), which accepts local paths and URLs. Never pass user input; otherwise it becomes a server-side request forgery or local file read primitive.
- The onboarding `refreshUrl` must point at an endpoint that only authorized callers can reach (the reference app signs it), because it mints a fresh onboarding link for the account.
- The package has **no authorization** layer. Anything that can call the facade with a context can create checkouts, refunds and codes on that account. Authorize in the app before calling it.

### Money handling

- All amounts are integers in minor units, tax-inclusive. The package does not validate amounts, currencies, percentages or expiry windows; Stripe rejects some invalid values, the local drivers do not.
- Checkouts are direct charges on the connected account with `fees_collector` and `losses_collector` set to `stripe`. The platform takes no application fee in this version.

### Document links and tenancy

Document links are signed on path and query only (`signed:relative`), so they survive proxies that change scheme or host. The tenant must therefore come from a signed query parameter: a tenancy middleware that also reads a header (stancl's `InitializeTenancyByRequestData` reads `X-Tenant` first) would let a valid link be replayed against another tenant's document with the same id. Use a middleware that only reads the query parameter.

## Known limitations

| Area | Limitation |
| --- | --- |
| Installation | Tenant migrations are not auto-loaded; `loadMigrationsFrom()` only loads `database/migrations` (`src/PaymentsServiceProvider.php:23`). There is no migration publish tag. |
| Dependencies | `composer.json:8` requires only `illuminate/support` of the framework, but the code uses `Illuminate\Database`, `Illuminate\Http`, `Illuminate\Routing`, views, `Storage`, `Illuminate\Foundation\Events\Dispatchable` and `Illuminate\Foundation\Queue\Queueable`, so it effectively needs `laravel/framework`. It also requires `spatie/laravel-pdf` (`^2.0`), which needs a renderer of its own. |
| Accounts | `create()` does not persist a `PaymentAccount`; the app must. No method to update the display name, statement descriptor or support details after creation. No disconnect method. |
| Accounts | One country and default currency for all accounts (`payments.stripe.country`, `.currency`). |
| Accounts | `dashboardUrl()` is a generic dashboard link and detects test mode only by the `sk_test_` prefix (`src/Drivers/Stripe/StripeAccountDriver.php:129`); `rk_test_` restricted keys give the live URL. |
| Checkout | `mode: payment` only (no subscriptions, no setup). No application fees, no `on_behalf_of`, no payment method configuration. |
| Checkout | `expiresAt` is not checked against Stripe's 30 minutes to 24 hours window. |
| Checkout | A line item without `taxPercentage` under local tax gets no tax rate at all, silently. |
| Tax | One tax country per app (`payments.local.tax_country`). Rate chosen for today, not the supply date (`src/Drivers/Local/LocalTaxDriver.php:47`). |
| Tax | `StripeTaxRates` checks only the first 100 rates and calls the Stripe API once per line item per checkout (`src/Drivers/Stripe/StripeTaxRates.php:20`). |
| Discounts | Percentage discounts only. |
| Discounts | `validate()` does not check `provider_account_id` (`src/Drivers/Concerns/ValidatesDiscountCodes.php:19-22`). After switching Stripe accounts, old Stripe codes validate but fail in Checkout (`list()` does filter on the account). |
| Discounts | Reservations expire only by time: a hold whose checkout ended without `release()` blocks the code until `reserved_until`. |
| Discounts | If the promotion code call fails after the coupon was created, an orphan coupon remains at Stripe. |
| Invoices | `issue()` and `creditNote()` of the local driver return `pdfUrl = null`; the PDF is rendered on the queue. Call `find()` later for the link. |
| Invoices | The year of a local document number is the year of `now()` in the app's timezone. |
| Invoices | Stripe credit notes have no `hostedUrl`. `PaymentInvoiceIssued` for a Stripe credit note uses the invoice's `metadata['payment_uuid']` as reference, a key the host app has to set (`src/Drivers/Stripe/StripeInvoiceDriver.php`). |
| Invoices | The document view has fixed English labels and formats amounts with a comma as decimal separator; publish `payments-views` to change it. |
| Refunds | No refund webhooks; a `pending` refund that later fails is not reported. A refunded one-time code stays used. |
| Webhooks | No command to re-dispatch unprocessed or failed events; no pruning. The job sets no `$tries` or backoff. |
| Multi-tenancy | Assumes the app owns tenancy. The package has no notion of tenants beyond the two connections. |

## Suspected bugs and inconsistencies

These are observations from reading the code, not confirmed by tests.

1. **Malformed webhook body returns 500, not 400.** `json_decode(..., JSON_THROW_ON_ERROR)` throws `JsonException` (`src/Drivers/Stripe/StripeWebhookDriver.php:27`), but the controller only catches `SignatureVerificationException | UnexpectedValueException` (`src/Http/Controllers/WebhookController.php:22`). The body is decoded before the signature is checked.
2. **Stored but undispatched events are never processed.** The controller stores the event, then dispatches only when `wasRecentlyCreated` (`src/Http/Controllers/WebhookController.php:26-34`). If the dispatch fails, Stripe redelivers, `firstOrCreate()` finds the row and nothing is dispatched. Concurrent duplicate deliveries can also hit the unique index and return 500 (Stripe retries, which is harmless).
3. **Accounts v2 requirement entries are assumed to have a `minimum_deadline`.** `$entry->minimum_deadline->status` (`src/Drivers/Stripe/StripeAccountDriver.php:188`) would fail if Stripe returns an entry without it. Not verified against Stripe's schema.
4. **The logo temp file is never closed.** `tmpfile()` (`src/Drivers/Stripe/StripeAccountDriver.php:172`) is left open until the request or worker ends; in a long-running worker the handle lingers until garbage collection.
5. **`AccountDefaultsData` docblock says the display name stays writable**, but `ManagesAccounts` has no method to change it.
6. **`PaymentContext::forAccount()` hard-codes Stripe-managed sources** (`src/PaymentContext.php:38`). Fine for account calls, but using such a context for invoices, tax or discounts silently picks the Stripe drivers.

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
