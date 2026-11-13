# Refunds

[Home](Home.md) › Refunds

## Contract

```php
interface IssuesRefunds
{
    /** Returns money of a payment to the customer; the whole payment when no amount is given. */
    public function refund(PaymentContext $context, string $providerPaymentId, ?int $amount = null): ProviderRefundData;
}
```

Picked by `$context->provider`. Only `StripeRefundDriver` exists.

## Stripe

```php
$refund = Payments::refunds($context)->refund($context, $providerPaymentId);         // full
$refund = Payments::refunds($context)->refund($context, $providerPaymentId, 500);    // 5.00 in minor units
```

`StripeRefundDriver::refund()` calls `refunds.create(['payment_intent' => $providerPaymentId, 'amount' => $amount])` with `['stripe_account' => $context->requireAccount()]` (`amount` omitted when `null`). Because checkouts are direct charges on the connected account, the refund comes out of that account's balance. Stripe emails the refund receipt according to the account holder's email settings.

`$providerPaymentId` is the PaymentIntent id from `ProviderCheckoutData::$providerPaymentId`.

### ProviderRefundData

| Property | Type | Notes |
| --- | --- | --- |
| `providerRefundId` | `string` | `re_...` |
| `amount` | `int` | Minor units actually refunded. |
| `status` | `string` | Stripe's status, e.g. `succeeded`, `pending`, `failed`. |

Stripe errors (`ApiErrorException`) are not caught. A `pending` refund can still fail later; the package does not listen to refund webhooks (`charge.refund.updated`, `refund.*`), so the app does not hear about it unless it adds that itself.

## The account to refund on

Use the account the payment was made on, not the merchant's current account:

```php
$context = app(ResolvesPaymentContext::class)->current()->withAccount($payment->provider_account_id);
```

## Refunds and invoices

| Invoicing source of the payment | What to do after the refund |
| --- | --- |
| `stripe` | Call `creditNote($context, $invoiceReference, $refund->amount, $refund->providerRefundId)`. It creates a Stripe credit note linked to the refund (so Stripe does not refund again) and dispatches `PaymentInvoiceIssued`, because Stripe does not email credit notes. |
| `local` | Call `creditNote($context, $invoiceReference, $refund->amount)`. It creates a credit note document with its own number, sets the invoice to `partially_credited` or `credited`, renders the PDF on the queue and then dispatches `PaymentInvoiceIssued`. |

Both return the credit note as `InvoiceData` (`type = credit_note`). The amount may not exceed what is left to credit on the invoice. See [Invoices](Invoices.md#crediting).

```mermaid
sequenceDiagram
    autonumber
    actor Admin
    participant App as Host app
    participant Pkg as Payments facade
    participant Stripe
    participant DB as payment_invoices
    participant Q as RenderInvoicePdfJob

    Admin->>App: refund payment
    App->>App: context = current()->withAccount(payment account)<br/>->withInvoicingSource(payment source)
    App->>Pkg: refunds(ctx)->refund(ctx, providerPaymentId)
    Pkg->>Stripe: POST refunds {payment_intent} (Stripe-Account)
    Stripe-->>Pkg: refund
    Pkg-->>App: ProviderRefundData
    App->>App: store refund id, amount, status
    alt invoice source local
        App->>Pkg: invoices(ctx)->creditNote(ctx, reference, amount, refundId)
        Pkg->>DB: insert credit note (CN series), update invoice credited_amount + status
        Pkg-)Q: dispatch after commit
        Q-->>App: PaymentInvoiceIssued (after the PDF is stored)
    else invoice source stripe
        App->>Pkg: invoices(ctx)->creditNote(ctx, reference, amount, refundId)
        Pkg->>Stripe: POST credit_notes {invoice, amount, refunds} (Stripe-Account)
        Pkg-->>App: PaymentInvoiceIssued
    end
    App->>Pkg: invoices(ctx)->find(ctx, reference)
    Note over App: invoice status partially_credited or credited
    Note over App: mail the credit note on PaymentInvoiceIssued
```

## Not included

- No refund records in the package's tables; the app stores what it needs.
- No local refund driver.
- No partial-refund bookkeeping beyond what the app stores, the credit notes and `credited_amount` on local invoices.
- No discount code un-redeeming: a refunded one-time code stays used.
