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
| **Credit / credit note** | Reduction of an issued invoice after a refund. Locally recorded as `credited_amount` on the invoice. |
| **Direct charge** | A charge created on the connected account (`Stripe-Account` header); funds and refunds are on that account. |
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
| **`PaymentContext`** | Immutable value passed to every call: provider, account id, sources, issuer. |
| **`PaymentProfile`** | Named presets of sources: `stripe_managed`, `stripe_with_local_discounts`, `locally_managed`. |
| **Platform** | The Stripe account of the host app's operator, whose secret key is `STRIPE_SECRET`. |
| **Promotion code** | Stripe object with the customer-facing code for a coupon (`promo_...`), with redemption limits and expiry. |
| **Provider (PSP)** | Payment service provider. `Provider` enum; only `stripe`. |
| **Redeem** | Record that a paid checkout used a discount code (`times_redeemed`). |
| **Reference** | `InvoiceData::$reference`: provider invoice id or local invoice row id; also `IssueInvoiceData::$reference`, the app's id of what is invoiced. |
| **Snapshot event** | A classic (v1) Stripe event carrying the full object. |
| **Statement descriptor** | The text on the customer's bank statement. |
| **Tenant connection** | `payments.connections.tenant`: the database of discount codes, tax rates and local invoices; with stancl/tenancy the current tenant's database. |
| **Thin event** | A v2 Stripe event (`object: v2.core.event`) carrying only a reference to the changed object. |
