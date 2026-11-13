# Security notes and known limitations

[Home](Home.md) › Security and Limitations

The package is a proof of concept. This page lists what it does not do, what the host app is responsible for, and places where the code looks incomplete or wrong. File references are relative to the repository root.

## Status

- **No automated tests** and no CI in this repository.
- **API may change**: class names, DTO fields, config keys and migrations can change without a deprecation path.
- **One provider**: only Stripe. Accounts, checkouts and refunds have no other driver.
- **Skeletons**: `LocalInvoiceDriver` (no PDF, email, hosted page or credit note document) and `StripeInvoiceDriver::creditNote()` (throws).

## Security notes

### Webhooks

- Signatures are verified for both payload styles with Stripe's SDK and the default tolerance of 300 seconds (`src/Drivers/Stripe/StripeWebhookDriver.php`). Keep both signing secrets out of version control.
- The route has no middleware group (`src/PaymentsServiceProvider.php:24`): no throttle and no CSRF. That is normal for webhooks, but add rate limiting at the edge if the endpoint is exposed to abuse. In a multi-domain tenancy setup the route answers on every domain the app serves.
- Every verified event is stored, including types the package ignores. `payment_webhook_events.payload` can contain customer names, emails and addresses from Stripe objects. The package never prunes it; define a retention policy in the app.
- Processing happens on a queue without tenancy. Listeners must derive the tenant from `PaymentAccount` (central) and must not trust ids in event metadata for authorization.

### Discount codes

- `validate()` reveals whether a code exists and why it is invalid. **Rate limiting is the host app's job**: throttle every endpoint that calls it (quotes, bookings, checkout creation).
- **Codes must have enough entropy** if they are not meant to be public. A short code like `SUMMER` is guessable through any validation endpoint.
- Validation and redemption are separate. The package does not reserve a code between `validate()` and `redeem()`, so a local `ONE_TIME` code can be used by two concurrent checkouts. Stripe enforces `max_redemptions` for Stripe codes.
- A Stripe code's `valid_from` is not known to Stripe (`src/Drivers/Stripe/StripeDiscountCodeDriver.php:41-55`). With `allow_promotion_codes` (no `providerDiscountId` given), customers can apply a scheduled code in Stripe Checkout before its start date.

### Accounts

- `BrandingData::$logoPath` is read with `file_get_contents()` (`src/Drivers/Stripe/StripeAccountDriver.php:166`), which accepts local paths and URLs. Never pass user input; otherwise it becomes a server-side request forgery or local file read primitive.
- The onboarding `refreshUrl` must point at an endpoint that only authorized callers can reach (the reference app signs it), because it mints a fresh onboarding link for the account.
- The package has **no authorization** layer. Anything that can call the facade with a context can create checkouts, refunds and codes on that account. Authorize in the app before calling it.

### Money handling

- All amounts are integers in minor units, tax-inclusive. The package does not validate amounts, currencies, percentages or expiry windows; Stripe rejects some invalid values, the local drivers do not.
- Checkouts are direct charges on the connected account with `fees_collector` and `losses_collector` set to `stripe`. The platform takes no application fee in this version.

## Known limitations

| Area | Limitation |
| --- | --- |
| Installation | Tenant migrations are not auto-loaded; `loadMigrationsFrom()` only loads `database/migrations` (`src/PaymentsServiceProvider.php:23`). There is no migration publish tag. |
| Dependencies | `composer.json:8` requires only `illuminate/support`, but the code uses `Illuminate\Database`, `Illuminate\Http`, `Illuminate\Foundation\Events\Dispatchable` and `Illuminate\Foundation\Queue\Queueable`, so it effectively needs `laravel/framework`. |
| Accounts | `create()` does not persist a `PaymentAccount`; the app must. No method to update the display name, statement descriptor or support details after creation. No disconnect method. |
| Accounts | One country and default currency for all accounts (`payments.stripe.country`, `.currency`). |
| Accounts | `dashboardUrl()` is a generic dashboard link and detects test mode only by the `sk_test_` prefix (`src/Drivers/Stripe/StripeAccountDriver.php:129`); `rk_test_` restricted keys give the live URL. |
| Checkout | `mode: payment` only (no subscriptions, no setup). No application fees, no `on_behalf_of`, no payment method configuration. |
| Checkout | `expiresAt` is not checked against Stripe's 30 minutes to 24 hours window. |
| Checkout | A line item without `taxPercentage` under local tax gets no tax rate at all, silently. |
| Tax | One tax country per app (`payments.local.tax_country`). Rate chosen for today, not the supply date (`src/Drivers/Local/LocalTaxDriver.php:47`). |
| Tax | `StripeTaxRates` checks only the first 100 rates and calls the Stripe API once per line item per checkout (`src/Drivers/Stripe/StripeTaxRates.php:20`). |
| Discounts | Percentage discounts only. `list()` returns all codes of all sources and accounts (`src/Drivers/Concerns/ValidatesDiscountCodes.php:38`). |
| Discounts | `validate()` does not check `provider_account_id` (`src/Drivers/Concerns/ValidatesDiscountCodes.php:17-20`). After switching Stripe accounts, old Stripe codes validate but fail in Checkout. |
| Discounts | If the promotion code call fails after the coupon was created, an orphan coupon remains at Stripe. |
| Invoices | Local invoices: no PDF, email or hosted page; `hostedUrl` and `pdfUrl` are always `null`. |
| Invoices | Local credits: no separate credit note number; `status` becomes `credited` even for a partial credit; no check that credits stay within `total` (`src/Drivers/Local/LocalInvoiceDriver.php:66-75`). |
| Invoices | Stripe credit notes are not implemented. |
| Refunds | No refund webhooks; a `pending` refund that later fails is not reported. A refunded one-time code stays used. |
| Webhooks | No command to re-dispatch unprocessed or failed events; no pruning. The job sets no `$tries` or backoff. |
| Multi-tenancy | Assumes the app owns tenancy. The package has no notion of tenants beyond the two connections. |

## Suspected bugs and inconsistencies

These are observations from reading the code, not confirmed by tests.

1. **Malformed webhook body returns 500, not 400.** `json_decode(..., JSON_THROW_ON_ERROR)` throws `JsonException` (`src/Drivers/Stripe/StripeWebhookDriver.php:27`), but the controller only catches `SignatureVerificationException | UnexpectedValueException` (`src/Http/Controllers/WebhookController.php:22`). The body is decoded before the signature is checked.
2. **Stored but undispatched events are never processed.** The controller stores the event, then dispatches only when `wasRecentlyCreated` (`src/Http/Controllers/WebhookController.php:26-34`). If the dispatch fails, Stripe redelivers, `firstOrCreate()` finds the row and nothing is dispatched. Concurrent duplicate deliveries can also hit the unique index and return 500 (Stripe retries, which is harmless).
3. **Local invoice numbering breaks after 9,999 invoices in a year.** The sequence is padded to 4 digits and the last number is found with a string `max()` (`src/Drivers/Local/LocalInvoiceDriver.php:39-43`). After `INV-2026-9999`, `INV-2026-10000` sorts lower, so the next number is `10000` again and the insert fails on the unique index. The year comes from `now()` in the app's timezone, and a prefix containing `%` or `_` would act as a `LIKE` wildcard.
4. **Accounts v2 requirement entries are assumed to have a `minimum_deadline`.** `$entry->minimum_deadline->status` (`src/Drivers/Stripe/StripeAccountDriver.php:188`) would fail if Stripe returns an entry without it. Not verified against Stripe's schema.
5. **The logo temp file is never closed.** `tmpfile()` (`src/Drivers/Stripe/StripeAccountDriver.php:172`) is left open until the request or worker ends; in a long-running worker the handle lingers until garbage collection.
6. **`AccountDefaultsData` docblock says the display name stays writable**, but `ManagesAccounts` has no method to change it.
7. **`PaymentContext::forAccount()` hard-codes Stripe-managed sources** (`src/PaymentContext.php:30`). Fine for account calls, but using such a context for invoices, tax or discounts silently picks the Stripe drivers.

## Responsibilities of the host app

| Concern | Package | Host app |
| --- | --- | --- |
| Who the merchant is | | `ResolvesPaymentContext` |
| Merchant's legal details | | `ProvidesIssuer` |
| Storing source choices | validation rule only | storage, UI |
| Persisting accounts | `SyncPaymentAccountAction` updates rows | creating rows, disconnect, reconnect |
| What is being paid for | | own payment records |
| Applying local discounts to amounts | | yes |
| Idempotent payment state changes | dedup of webhook events | row locks, status checks |
| Tenancy for webhook listeners | | yes |
| Authorization | | yes |
| Rate limiting of code validation | | yes |
| Tax rate seeding | | yes |
| Retention of webhook payloads | | yes |
