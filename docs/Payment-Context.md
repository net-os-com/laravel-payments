# Payment Context and capability sources

[Home](Home.md) › Payment Context

## PaymentContext

`NetOS\Payments\PaymentContext` says whose money a call handles and how. It is a `final readonly` class, passed to every facade method and every driver method, so drivers keep no state.

```php
final readonly class PaymentContext
{
    public function __construct(
        public Provider $provider,
        public ?string $providerAccountId,
        public CapabilitySourcesData $sources,
        public ?IssuerData $issuer = null,
        /** @var array<string, string> */
        public array $urlParameters = [],
    ) {}
}
```

| Property | Type | Meaning |
| --- | --- | --- |
| `provider` | `Provider` | The payment provider. Picks the accounts, checkouts and refunds driver. Only `Provider::STRIPE` (`stripe`) exists. |
| `providerAccountId` | `?string` | The merchant's account at the provider (a Stripe `acct_...` id). `null` before an account is connected. |
| `sources` | `CapabilitySourcesData` | Where invoicing, tax and discount codes run. |
| `issuer` | `?IssuerData` | The merchant's legal details for local invoices. Required by `LocalInvoiceDriver::issue()`. |
| `urlParameters` | `array<string, string>` | Extra parameters for links the package signs, e.g. `['tenant' => '...']`, so a document link opens in the right context. Added to the signed `hostedUrl` and `pdfUrl` of local documents and passed to `RenderInvoicePdfJob`. Default `[]`. See [Invoices](Invoices.md#hosted-page-and-pdf). |

### Methods

| Method | Returns | Use |
| --- | --- | --- |
| `static forAccount(Provider $provider, ?string $providerAccountId)` | `self` | A context for account-only calls (syncing, onboarding links, branding). Uses `PaymentProfile::STRIPE_MANAGED->sources()`, no issuer and no URL parameters. Do not use it for invoices, tax or discounts. |
| `withAccount(?string $providerAccountId)` | `self` | Same sources, issuer and URL parameters, another account. Used to act on a payment made on an earlier account. |
| `withSources(CapabilitySourcesData $sources)` | `self` | Same account, issuer and URL parameters, other sources. |
| `withDiscountSource(CapabilitySource $source)` | `self` | Only the discount source changed. Used to manage or redeem a code created under a source the merchant no longer uses. |
| `withInvoicingSource(CapabilitySource $source)` | `self` | Only the invoicing source changed. Used to read or credit an invoice created under an earlier source. |
| `requireAccount()` | `string` | The account id, or throws `AccountNotConnectedException` ("No payment account is connected."). Every Stripe driver method that calls Stripe on a connected account uses it. |

The `with*` methods do not call `assertAllowed()`; a context with an otherwise invalid combination can be built on purpose to reach an older record.

### Who builds it

The package never builds a full context itself, except `PaymentContext::forAccount()` inside `SyncPaymentAccountAction`. The host app builds it in its `ResolvesPaymentContext` implementation:

```php
interface ResolvesPaymentContext
{
    public function current(): PaymentContext;
}
```

See [Host App Integration](Host-App-Integration.md) for the reference implementation.

## CapabilitySourcesData

```php
class CapabilitySourcesData extends Data
{
    public function __construct(
        public CapabilitySource $invoicing,
        public CapabilitySource $tax,
        public CapabilitySource $discounts,
    ) {}

    public function problem(): ?string;
    public function assertAllowed(): self;
}
```

`CapabilitySource` is a string enum: `STRIPE = 'stripe'`, `LOCAL = 'local'`.

Payments themselves (accounts, checkouts, refunds) always run at the provider and have no source.

### Validation rule

> A local invoice cannot depend on amounts the provider calculates inside its checkout.

| Invoicing | Tax | Discounts | `problem()` |
| --- | --- | --- | --- |
| stripe | any | any | `null` |
| local | local | local | `null` |
| local | stripe | any | `Local invoicing needs local tax and local discount codes.` |
| local | any | stripe | `Local invoicing needs local tax and local discount codes.` |

`assertAllowed()` throws `InvalidArgumentException` with that message, or returns `$this` so it can be chained:

```php
$sources = (new CapabilitySourcesData($invoicing, $tax, $discounts))->assertAllowed();
```

A settings form should call `problem()` and turn the message into a validation error (the reference host app does this in `UpdatePaymentSourcesAction`).

### All valid combinations

| Invoicing | Tax | Discounts | Preset |
| --- | --- | --- | --- |
| stripe | stripe | stripe | `STRIPE_MANAGED` |
| stripe | stripe | local | `STRIPE_WITH_LOCAL_DISCOUNTS` |
| stripe | local | stripe | none |
| stripe | local | local | none |
| local | local | local | `LOCALLY_MANAGED` |

## PaymentProfile

`NetOS\Payments\Enums\PaymentProfile` names three combinations. `sources()` returns the matching `CapabilitySourcesData`.

| Case | Value | Invoicing | Tax | Discounts | Meaning |
| --- | --- | --- | --- | --- | --- |
| `STRIPE_MANAGED` | `stripe_managed` | stripe | stripe | stripe | The provider handles invoices, tax and discount codes inside its checkout. |
| `STRIPE_WITH_LOCAL_DISCOUNTS` | `stripe_with_local_discounts` | stripe | stripe | local | The provider invoices and calculates tax; discount codes are the app's own. |
| `LOCALLY_MANAGED` | `locally_managed` | local | local | local | The provider only takes the money; the app invoices, calculates tax and owns the codes. |

```php
$context = new PaymentContext(
    provider: Provider::STRIPE,
    providerAccountId: $accountId,
    sources: PaymentProfile::LOCALLY_MANAGED->sources(),
    issuer: app(ProvidesIssuer::class)->issuer(),
);
```

The profiles are presets only. Nothing in the package stores a profile; the app decides how to store a merchant's choice. The reference host app stores the three sources per tenant and defaults to stripe invoicing, local tax and stripe discounts, which is valid but not one of the presets.

## What each source changes

| | `stripe` | `local` |
| --- | --- | --- |
| Invoicing | Stripe creates, numbers, renders and emails the invoice in Checkout. `isHandledByCheckout()` is `true`. The app reads it with `find()`. | The app calls `issue()` after the payment. Numbered in `payment_invoices`, rendered to PDF on the queue, served on signed links; the app mails it on `PaymentInvoiceIssued`. |
| Tax | Stripe Tax (`automatic_tax`) in Checkout. `isHandledByCheckout()` is `true`; the app cannot know the tax before the checkout. | The app calls `calculate()` before the checkout and passes `taxPercentage` per line item. |
| Discounts | A Stripe coupon + promotion code, mirrored locally. Applied by Checkout. | A code only in `payment_discount_codes`. The app lowers the line items before the checkout. |

## Switching sources

A merchant can switch sources at any time; the package keeps no history. Records made under an earlier source keep their `source` column (discount codes) or must be remembered by the app (invoices). To act on them later, rebuild the context for that source:

```php
// Redeem a code created as a Stripe code, after the merchant switched to local codes
$codeContext = $context->withDiscountSource($discountCode->source);
Payments::discounts($codeContext)->redeem($codeContext, $discountCode);

// Credit an invoice issued locally, after the merchant switched to Stripe invoicing
$invoiceContext = $context->withInvoicingSource(CapabilitySource::LOCAL);
Payments::invoices($invoiceContext)->creditNote($invoiceContext, $invoiceReference, $amount);
```

The reference host app stores the invoicing source on each payment (`payments.invoice_source`) for this reason.
