# Local capabilities at parity with Stripe — design

Date: 2026-11-13 · Package: `net-os/laravel-payments` · Host app: Net OS (`spike/payg`)

## Intent

Complete the `local` sources for **invoices, discount codes and tax** so a tenant on the "Locally managed" profile can run the whole guest flow (book → pay → invoice → mail → refund → credit note) and it behaves like the Stripe-managed flow. The goal is testing the full flow locally, "as perfect as possible".

Guiding rule (from the product owner): **driver parity**. Whatever the Stripe driver of a capability can do, the local driver can do, and vice versa. The host app must not need to know which source produced a result.

## Goals

- Local invoices that look and behave like Stripe invoices: a number, a hosted page (`hostedUrl`), a PDF (`pdfUrl`), the guest's company details, and an email to the guest with the PDF attached.
- Credit notes as separate documents with their own number and PDF, created on refund.
- Gapless invoice numbering that does not break after 9,999 documents a year.
- Local discount codes without the known gaps: a one-time code cannot be used twice concurrently, and listing returns only the codes of the active source.
- Local tax unchanged in behavior (inclusive rate table), documented as at parity.

## Non-goals

- Fixed-amount discount codes (the Stripe driver supports percentages only, so parity means percentages only).
- A management screen for tax rates (Stripe source has none either; rates stay in `payment_tax_rates` and the seeder).
- Reverse-charge VAT for foreign B2B guests. The price is fixed before Checkout asks for the VAT number. Workspace rental is assumed to be taxed where the property is (always Dutch VAT); **to be confirmed by a tax advisor, not part of this build**.
- Real email delivery locally: the mailer is `log`.
- Showing credit notes in Net OS (operator screen or guest page): the guest gets them by email only.
- Legal compliance review of the invoice layout beyond the fields listed below.

## Anti-goals

- Host-app code that branches on the invoice source to get a URL, a PDF or an email.
- Rendering the PDF on every request (it is rendered once and stored).
- Sending guest personal data anywhere except Cloudflare's PDF rendering (accepted processor) and Stripe (already).

## Constraints

- Documents are stored on **GCS in every environment** (Net OS: the tenant-suffixed `gcs` disk), so web and queue pods share them; Cloudflare credentials become deploy secrets wherever the flow runs.
- PDF rendering with **`spatie/laravel-pdf` v2, Cloudflare driver only** (`LARAVEL_PDF_DRIVER=cloudflare`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`), also locally. Approved dependency of the **package**, not of Net OS.
- Mail locally via the `log` mailer.
- Package is PSP-agnostic and multi-tenant (tenant DB connection from `payments.connections.tenant`).
- Spike branch: no automated tests in Net OS; the package may use `Pdf::fake()` if tests are added later.

## Approaches considered

1. **Everything in the package (chosen).** The local invoice driver renders and stores the PDF, the package serves a signed hosted page and PDF route, credit notes are package documents, the package fires `PaymentInvoiceIssued`; the host only sends the mail. Keeps the host code identical for both sources — exactly what parity asks for.
2. **Package does data and numbering; host renders PDF and page.** Less package work, but the local driver would return no `hostedUrl`/`pdfUrl` while Stripe does, and every host app would rebuild it. Rejected: breaks parity.

## Design

### 1. Guest company details from Checkout (all invoice sources)

- `StripeCheckoutDriver` always asks for billing details: `billing_address_collection: required` and `tax_id_collection.enabled: true` (also when invoicing is local).
- `ProviderCheckoutData` gets a nullable `customer` (`CustomerDetailsData`: name, email, company name, address lines, postal code, city, country, tax id type + value) filled from the session's `customer_details`.
- `IssueInvoiceData` gets a nullable `customer` of the same type; the local invoice stores it as `payer` json and prints it.
- Net OS: `SyncGuestPaymentAction` passes the session's customer details into `IssueLocalInvoiceAction`.

### 1b. Issuer details: tenant billing profile in Net OS

- Net OS gets a **billing profile per tenant**: legal name, address (street + number, postal code, city, country), chamber of commerce number, VAT number. Stored in the tenant's `data` (like `payment_settings`), key `billing_profile`.
- Action `UpdateTenantBillingProfileAction` + data object with validation (NL VAT format `NL\d{9}B\d{2}`, KvK 8 digits), endpoints `GET/PUT payments/billing-profile` behind the existing PAYG authorization, and a card on the operator's **Payment integration** page to view and edit it.
- `TenantIssuerProvider` returns the profile as `IssuerData` (address formatted on lines). Local invoicing refuses to issue without a complete profile (clear error), and the Payment integration page shows the profile as a configuration check.

### 2. Documents and numbering

- `payment_invoices` gets `type` (`invoice` | `credit_note`), `credits_invoice_id` (nullable self reference), `payer` (json), `pdf_path` (nullable), `pdf_disk` (nullable). A credit note is a row with negative amounts and its own lines.
- New tenant table `payment_document_sequences` (`series` string, `year` int, `last_number` int, unique `series+year`). Numbering: lock the row (`lockForUpdate`, insert when missing) inside the transaction, increment, format `{prefix}-{year}-{number padded to 4, longer when needed}`. Series: invoices use `local.invoice_number_prefix` (default `INV`), credit notes a new `local.credit_note_number_prefix` (default `CN`). The year is the issue date's year. This replaces the `LIKE`/string `max()` lookup.
- Status of an invoice: `issued`, then `partially_credited` or `credited` depending on the sum of its credit notes. `creditNote()` refuses to credit more than the remaining amount.

### 3. PDF, hosted page and storage

- Blade views in the package (`payments::invoices.document`, publishable under tag `payments-views`), one layout for invoice and credit note: issuer (name, address, chamber of commerce, VAT number), payer (name, email, company, address, VAT id), number, issue date, reference, lines with VAT percentage, discount line, totals (incl. VAT, of which VAT per rate), "credit note for invoice X" when applicable. Plain tables, no flex/grid needed.
- Queued job `RenderInvoicePdfJob` (on `payments.queue`) renders with `Pdf::view(...)->driver('cloudflare')` and saves to the disk `payments.local.invoice_disk` (new config; Net OS sets it to `gcs` everywhere) under `invoices/{year}/{number}.pdf`; stores `pdf_path`/`pdf_disk`, then fires `PaymentInvoiceIssued`.
- Package routes (registered by the service provider, signed, no auth) under a configurable prefix `payments.routes.documents_prefix` (default `payments/documents`, so it does not collide with Net OS's authenticated `payments/invoices/{payment}`): `GET {prefix}/{document}` (HTML page, same view) and `GET {prefix}/{document}/pdf` (stream from disk; 404 until rendered). `hostedUrl`/`pdfUrl` are signed URLs **without expiry** (like Stripe's hosted invoice links), generated in `toData()`.
- Tenancy: the routes need the tenant context. `PaymentContext` gets `array $urlParameters` (default `[]`), filled by the host's `ResolvesPaymentContext`; the package merges them into the signed URLs. Net OS fills `['tenant' => tenant id]`, which its tenant-by-request-data middleware reads. The host adds that middleware through config `payments.routes.documents_middleware`. Queued code that calls `find()` gets the context from the same resolver, so the parameters are always present.

### 4. Event and mail

- New event `PaymentInvoiceIssued(InvoiceData $invoice, string $reference, ?string $payerEmail)` fired after the PDF is stored, for invoices **and** credit notes, from local drivers only. Stripe keeps mailing its own invoices (parity: in both cases the guest gets a mail with the document).
- Net OS: listener `SendGuestInvoiceMailOnInvoiceIssued` → `GuestInvoiceMail` (Mailable, queued) to the payer email, subject "Your invoice {number}" / "Credit note {number}", body with the hosted link, PDF attached from the disk. Locally it ends up in the log.
- Net OS stores the URLs/number on `payments` as today (`invoice_url`, `invoice_pdf_url`), so the operator's Invoices screen and the guest success page work unchanged.

### 5. Credit notes on refund

- `RefundPaymentAction` keeps calling `Payments::invoices($context)->creditNote(...)`. Local: creates the credit note document (lines: one line "Refund for invoice X" with the refunded amount split by VAT rate proportionally), renders its PDF, fires the event.
- Stripe parity: implement `StripeInvoiceDriver::creditNote()` with Stripe's Credit Notes API on the checkout invoice, linked to the existing refund (the contract gets an optional `?string $providerRefundId`) so Stripe does not refund twice. Net OS removes the "local only" branch in `RefundPaymentAction` and calls `creditNote()` for both sources.
- Stripe does not email credit notes, so `PaymentInvoiceIssued` also fires for **Stripe credit notes** (from the Stripe driver), and Net OS mails every credit note itself. Stripe invoices are still mailed by Stripe.
- Status parity: both drivers report `issued`/`paid` → `partially_credited` → `credited`. The Stripe driver derives it from the invoice's credited amount (`post_payment_credit_notes_amount` vs total).

### 6. Discount codes

- **Reservation for one-time codes**: new nullable column `reserved_until` + `reserved_reference` on `payment_discount_codes`. `ManagesDiscountCodes` gets `reserve(context, code, reference, until)` and `release(context, code, reference)`. Local driver: atomic conditional update (only when not redeemed and not reserved by another reference or the reservation expired). Stripe driver: no-op (Stripe enforces `max_redemptions` itself) — the contract stays the same for both. Net OS reserves when creating the guest booking (until the checkout expiry) and releases on expire/cancel; `redeem()` clears the reservation.
- Validation treats a code reserved by another reference as "This code is already in use." (new `DiscountCodeStatus` reason).
- `list()` filters on the context's discount source (and on the provider account for Stripe codes).
- Percentages only, as today.

### 7. Tax

- No behavior change. Documented: local inclusive VAT per line from `payment_tax_rates`, Stripe uses inclusive tax rates or Stripe Tax; both return the same `TaxedLineData`.

### 8. Known bugs fixed along the way

- Invoice numbering after 9,999 and `LIKE` wildcards (replaced by sequences).
- Credit amount not capped, status always `credited`.

### Config added

| Key | Env | Default |
|---|---|---|
| `local.credit_note_number_prefix` | `PAYMENTS_CREDIT_NOTE_PREFIX` | `CN` |
| `local.invoice_disk` | `PAYMENTS_INVOICE_DISK` | `local` (Net OS: `gcs`) |
| `routes.documents_prefix` | — | `payments/documents` |
| `routes.documents_middleware` | — | `[]` |

## Decided by the product owner

Local flow for testing, as perfect as possible · invoice as hosted page **and** PDF · `spatie/laravel-pdf` with **Cloudflare only** · mail locally via **log** · company details taken **from Stripe Checkout** · strict **driver parity** (so percentages only) · approach 1, everything in the package · issuer details from a **tenant billing profile** in Net OS (with settings screen) · credit notes **not shown** in Net OS, email only · documents on **GCS everywhere**.

## Calls made by the designer (check these)

- Credit notes are separate documents with their own `CN` series; partial credits allowed, capped at the invoice total.
- Hosted/PDF links are signed without expiry.
- Package routes on a configurable `payments/documents` prefix; tenant passed via `PaymentContext::$urlParameters`.
- Net OS mails all credit notes (also Stripe's); Stripe invoices stay mailed by Stripe.
- Same invoice statuses for both drivers.
- Billing profile stored in tenant `data`, edited on the Payment integration page; local invoicing requires it.
- `StripeInvoiceDriver::creditNote()` is implemented for parity.
- Reservation contract added to `ManagesDiscountCodes`, no-op on Stripe.
- No tax-rate UI; reverse charge out of scope.

## Left to the builder

Exact Blade markup and styling, job retry settings, names of migrations, how the Mailable is styled, and whether the hosted page and the PDF share one view or the page wraps it.
