# Glossary

[Home](Home.md) › Glossary

| Term | Meaning |
| --- | --- |
| **Account holder** | The person or business that owns a connected account and completes onboarding. In Net OS: the workspace operator. |
| **Accounts v2** | Stripe's `v2/core/accounts` API, used to create and read connected accounts with configurations (`merchant`) instead of account types. |
| **Capability** | A unit of payment functionality with its own contract and manager: accounts, checkouts, refunds, invoicing, tax, discount codes, webhooks. |
| **Capability source** | `CapabilitySource`: where invoicing, tax or discount codes run, `stripe` or `local`. |
| **`CapabilitySourcesData`** | The three sources of one merchant, with the rule that local invoicing needs local tax and discounts. |
| **Central connection** | `payments.connections.central`: the database of `payment_accounts` and `payment_webhook_events`, shared by all tenants. |
| **Checkout** | A hosted payment page. With Stripe a Checkout Session (`cs_...`). |
| **Connected account** | A merchant's Stripe account under the platform (`acct_...`). |
| **Coupon** | Stripe object holding the discount percentage. |
| **Credit note** | A document of its own that credits (part of) an issued invoice after a refund, with its own number and PDF and positive amounts. Locally a `payment_invoices` row with `type = credit_note` and `credits_invoice_id`; at Stripe a credit note from the Credit Notes API. `creditNote()` returns it. |
| **Customer details** | `CustomerDetailsData`: the billing details the customer entered in Stripe Checkout (name, email, company, address, tax id), returned as `ProviderCheckoutData::$customer` and printed on local invoices as `payer`. |
| **Direct charge** | A charge created on the connected account (`Stripe-Account` header); funds and refunds are on that account. |
| **Document** | A local invoice or credit note: a `payment_invoices` row with a hosted page and a PDF. |
| **Document link** | A signed URL without expiry to a local document's page (`payments.documents.show`) or PDF (`payments.documents.pdf`), carrying `PaymentContext::$urlParameters`. |
| **Document sequence** | A `payment_document_sequences` row: the last number of a series (e.g. `INV`, `CN`) in a year, handed out by `DocumentNumberer` without gaps. |
| **Driver** | A class implementing a capability contract for one provider or source, e.g. `StripeCheckoutDriver`. |
| **Event destination** | Stripe's configuration that sends events to a URL, with its own signing secret. |
| **Handled by checkout** | `isHandledByCheckout()` is true: the provider does the capability inside Checkout, so the app must not call it directly. |
| **Host app** | The Laravel application that installs the package. |
| **Inclusive tax** | Tax included in the price; the package always works with tax-inclusive amounts. |
| **Issuer** | `IssuerData`: the merchant's legal details on local invoices. |
| **Local** | The capability runs in the host app with the package's tables, not at the provider. |
| **Manager** | An `Illuminate\Support\Manager` per capability that creates and caches drivers. |
| **Merchant** | Whoever receives the money. Usually a tenant. |
| **Minor units** | Integer amounts in the currency's smallest unit (cents). |
| **Mirror** | The local `payment_discount_codes` row of a Stripe code, keeping fields Stripe has no place for. |
| **Onboarding link** | A single-use Stripe account link where the account holder provides their details. |
| **Owner** | The host app model a `PaymentAccount` belongs to (`owner_type`, `owner_id`). |
| **Partially credited** | Invoice status when its credit notes are above 0 but below its total; `credited` once they reach it. Same for both drivers. |
| **`PaymentContext`** | Immutable value passed to every call: provider, account id, sources, issuer, URL parameters of signed links. |
| **`PaymentInvoiceIssued`** | Event dispatched when a document the host app has to deliver is ready: a local invoice or credit note after its PDF is stored, or a Stripe credit note. The host app mails it. |
| **`PaymentProfile`** | Named presets of sources: `stripe_managed`, `stripe_with_local_discounts`, `locally_managed`. |
| **Platform** | The Stripe account of the host app's operator, whose secret key is `STRIPE_SECRET`. |
| **Promotion code** | Stripe object with the customer-facing code for a coupon (`promo_...`), with redemption limits and expiry. |
| **Provider (PSP)** | Payment service provider. `Provider` enum; only `stripe`. |
| **Redeem** | Record that a paid checkout used a discount code (`times_redeemed`); also ends a reservation. |
| **Reference** | `InvoiceData::$reference`: provider invoice or credit note id, or local row id; also `IssueInvoiceData::$reference`, the app's id of what is invoiced; and the checkout reference that holds a discount code reservation. |
| **Reservation** | A hold on a local one-time discount code by one open checkout (`reserved_reference`, `reserved_until`), taken with `reserve()` and freed with `release()` or `redeem()`, or when it expires. Validation reports `RESERVED` for other checkouts. |
| **Series** | The prefix a document number starts with, numbered per year: `local.invoice_number_prefix` (`INV`) for invoices and `local.credit_note_number_prefix` (`CN`) for credit notes. |
| **Snapshot event** | A classic (v1) Stripe event carrying the full object. |
| **Statement descriptor** | The text on the customer's bank statement. |
| **Tenant connection** | `payments.connections.tenant`: the database of discount codes, tax rates, local documents and document sequences; with stancl/tenancy the current tenant's database. |
| **Thin event** | A v2 Stripe event (`object: v2.core.event`) carrying only a reference to the changed object. |
