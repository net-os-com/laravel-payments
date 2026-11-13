# Testing

[Home](Home.md) › Testing

## Setup

| Part | What it is |
| --- | --- |
| Runner | Pest (`pestphp/pest: ^3.8`), configured in `phpunit.xml` with two suites, `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`). |
| Application | Orchestra Testbench (`orchestra/testbench: ^10.0`). `tests/TestCase.php` boots `LaravelDataServiceProvider`, spatie/laravel-pdf's `PdfServiceProvider` and `PaymentsServiceProvider`. |
| Database | In-memory SQLite (the `testing` connection). `tests/Pest.php` applies `RefreshDatabase` to both suites, and `TestCase` loads both `database/migrations` and `database/migrations/tenant`, so the central and tenant tables live in one database. |
| Config | A fixed `app.key`, `payments.stripe.secret = sk_test_package`, snapshot and thin webhook secrets (`whsec_snapshot`, `whsec_thin`), and a `local` disk under the system temp directory for PDFs. |
| Stripe | `tests/Support/FakeStripe.php`, see below. No network access or Stripe keys are needed. |
| Contexts | `TestCase` helpers build a `PaymentContext` for account `acct_test` with an issuer and `urlParameters = ['tenant' => 'acme']`: `context($profile = LOCALLY_MANAGED, $account = 'acct_test')`, `localContext()` (locally managed) and `stripeContext()` (Stripe managed). `withLocalDiscounts($context)` switches only the discount source of a given context. |

### FakeStripe

`NetOS\Payments\Tests\Support\FakeStripe` implements Stripe's `Stripe\HttpClient\ClientInterface`. `TestCase::setUp()` registers it with `Stripe\ApiRequestor::setHttpClient()` and `tearDown()` removes it again, so the real `StripeClient` and the real drivers run, and only the HTTP layer is replaced.

| Method | Purpose |
| --- | --- |
| `on(string $route, array $body, int $status = 200)` | Queues a response for `"METHOD /path"`, e.g. `'POST /v1/checkout/sessions'`. `*` matches one path segment. Responses for a route are returned in order; the last one repeats. |
| `$requests` | Every request made: method, path, params (body and query) and headers. |
| `last(string $method, string $pathPattern)` | The most recent request matching the method and a path regex; throws when there is none. |
| `count(string $method, string $pathPattern)` | How many requests matched. |

A request without a registered response throws `RuntimeException('No fake Stripe response for ...')`, so a test fails on any Stripe call it did not expect.

The fake answers with what the test registers. It does not validate requests the way Stripe does, so the suite proves what the package sends, not that Stripe accepts it.

## Running the tests

```bash
composer install
composer test
```

`composer test` runs `pest`. Run one file or test with `vendor/bin/pest tests/Feature/WebhookTest.php` or `vendor/bin/pest --filter="rejects a body"`.

## CI

`.github/workflows/run-tests.yml` (workflow `run-tests`) runs on pushes and pull requests that change PHP files, `resources/`, `composer.json`, `phpunit.xml` or the workflow itself. The matrix is PHP 8.4 and 8.5 with Laravel 12 (`laravel/framework: 12.*`, `orchestra/testbench: 10.*`). It installs with `--prefer-stable` and runs `vendor/bin/pest --ci`.

## Test files

### Unit

| File | Covers |
| --- | --- |
| `tests/Unit/DocumentNumbererTest.php` | Gapless numbering per series and year, numbers past 9999, seeding a sequence from documents numbered before it existed, and `LIKE` wildcards in a series. |
| `tests/Unit/LocalDiscountCodeDriverTest.php` | Upper-case storage and case-insensitive lookup, validation reasons, reserving and releasing one-time codes, expiry of holds, redemption, and filtering by the active source. |
| `tests/Unit/LocalInvoiceDriverTest.php` | Issuing a numbered invoice, refusing without an issuer, partial and full credit notes, refusing a credit of zero or less, VAT cancelled to the cent on a full credit, partial credits split over VAT rates without negative lines, and crediting what is left per rate after earlier credits. |
| `tests/Unit/LocalTaxDriverTest.php` | Inclusive VAT per line from the rate table, refusing a category without a rate, and picking the newest rate valid today. |
| `tests/Unit/PaymentContextTest.php` | `with...()` helpers keep the issuer and URL parameters, `requireAccount()`, the allowed source combinations and every `PaymentProfile`. |

### Feature

| File | Covers |
| --- | --- |
| `tests/Feature/DocumentRoutesTest.php` | Signed hosted document page, tampered links, `404` for a PDF that is not rendered or missing on disk, and links that survive a different scheme or host. |
| `tests/Feature/RenderInvoicePdfJobTest.php` | `RenderInvoicePdfJob` renders and stores the PDF once and dispatches `PaymentInvoiceIssued` (with `Pdf::fake()`), and a credit note queues the job too. |
| `tests/Feature/StripeCheckoutDriverTest.php` | Session parameters per source profile, `InvalidArgumentException` for an expiry outside Stripe's window or a line without `taxPercentage`, and mapping paid and expired sessions. |
| `tests/Feature/StripeDriversTest.php` | Stripe invoice statuses after credits, Stripe credit notes, Stripe discount codes (including removing the coupon when the promotion code fails and validating per account), paging through tax rates, refunds, the test dashboard URL, refusing a logo URL, replacing a driver with `extend()`, and requirements without a minimum deadline. |
| `tests/Feature/WebhookTest.php` | `400` for a non-JSON body or a bad signature, `404` for an unknown provider, storing and queuing an event once, queuing a redelivery of an unprocessed event, `PaymentCheckoutUpdated` for a checkout event, and `payments:retry-webhooks`. |
