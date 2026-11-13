# Local Capabilities at Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Local invoices (with PDF, hosted page, credit notes and mail), local discount codes and local tax that behave exactly like the Stripe drivers, so the full guest flow can be tested on the "Locally managed" profile.

**Architecture:** Everything source-specific lives in the package `net-os/laravel-payments` (drivers, documents, numbering, PDF, signed routes, events). Net OS only supplies the tenant billing profile, URL parameters and the mail, and calls the same package API for both sources.

**Tech Stack:** PHP 8.4, Laravel 12, stancl/tenancy (multi-database), spatie/laravel-data, `spatie/laravel-pdf` v2 (Cloudflare driver), stripe-php 22, Expo/React Native web (NativeWind, react-query, tanstack-form).

**Spec:** `docs/superpowers/specs/2026-11-13-local-capabilities-design.md` (this repo).

## Global Constraints

- Two repos: package `~/Code/laravel-payments` (branch `main`, commit per task, do not push) and Net OS `~/Code/NetOS-monorepo` (branch `spike/payg`, **do not commit**, the user commits).
- Spike: **no automated tests** in Net OS and none required in the package; every task ends with a manual verification (tinker probe or browser) whose expected output is given.
- PDF rendering: `spatie/laravel-pdf` v2, Cloudflare driver only (`LARAVEL_PDF_DRIVER=cloudflare`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`), dependency of the **package**. No other new dependencies.
- Documents stored on the disk `payments.local.invoice_disk`; Net OS sets it to `gcs` (tenant-suffixed) in every environment.
- Mail in Net OS locally via the `log` mailer.
- Driver parity: whatever one source's driver returns/does, the other does too. Discount codes are percentage only.
- Composer/artisan run inside Docker: `docker compose exec -T php <cmd>` from `~/Code/NetOS-monorepo`. The package is mounted at `/laravel-payments` and symlinked into `vendor/net-os/laravel-payments`.
- Code and docs in American English; Net OS conventions apply (Actions via `make()->handle()`, DTOs, CRUD-only controllers, `App` components).
- Never put tokens or keys in committed files.

## Review Focus

1. **Two refunds on one invoice** — the second credit note must be capped at the remaining amount and the invoice ends `credited`, not over-credited. (Task 3, verification step.)
2. **Two guests using the same one-time code at the same time** — the second booking gets "This code is already in use." while the first checkout is open; after the first checkout expires the code is usable again. (Task 5, verification step.)
3. **Opening the PDF link before rendering finished** — returns 404, not a 500; the hosted page still works. (Task 4, verification step.)
4. **Local invoicing without a complete billing profile** — the payment still syncs as paid; the invoice is not issued and the error is logged, so the guest is not left with a failed booking. (Task 8, verification step.)
5. **A tampered or tenant-swapped document link** — invalid signature gives 403; a valid link with another tenant parameter does not reveal another tenant's document. (Task 4, verification step.)

---

### Task 1: Customer details from Checkout

**Files:**
- Create: `src/Data/CustomerDetailsData.php`
- Modify: `src/Data/ProviderCheckoutData.php`, `src/Data/IssueInvoiceData.php`, `src/Drivers/Stripe/StripeCheckoutDriver.php`

**Interfaces:**
- Produces: `CustomerDetailsData(?string $name, ?string $email, ?string $companyName, ?string $addressLine1, ?string $addressLine2, ?string $postalCode, ?string $city, ?string $country, ?string $taxIdType, ?string $taxIdValue)`; `ProviderCheckoutData::$customer` (`?CustomerDetailsData`, last constructor param, default `null`); `IssueInvoiceData::$customer` (`?CustomerDetailsData`, default `null`).

- [ ] **Step 1:** Add `CustomerDetailsData` (spatie Data) and the two nullable properties.
- [ ] **Step 2:** In `StripeCheckoutDriver::create()` always set `billing_address_collection => 'required'` and `tax_id_collection => ['enabled' => true]` (remove the invoicing condition at line ~67). In `toData()` map `customer_details` (name, email, `address.line1/line2/postal_code/city/country`, first `tax_ids[]` type/value; company name = `customer_details.business_name` when present, else null) into `customer`.
- [ ] **Step 3: Verify** — in Net OS tinker, fetch a completed test session: `Payments::checkouts($ctx)->fetch($ctx, '<cs_test_id>')->customer` → object with name/email and address fields filled. Expected: no exception, `country` = `NL`.
- [ ] **Step 4: Commit** `feat: return customer details from Stripe Checkout`.

### Task 2: Document schema and gapless numbering

**Files:**
- Create: `database/migrations/tenant/2026_11_13_000001_add_document_columns_to_payment_invoices_table.php`, `database/migrations/tenant/2026_11_13_000002_create_payment_document_sequences_table.php`, `src/Models/PaymentDocumentSequence.php`, `src/Support/DocumentNumberer.php`
- Modify: `src/Models/PaymentInvoice.php`, `src/Drivers/Local/LocalInvoiceDriver.php`, `config/payments.php`

**Interfaces:**
- Produces: columns on `payment_invoices`: `type` string default `invoice` (`invoice`|`credit_note`), `credits_invoice_id` nullable FK to `payment_invoices.id` (`restrictOnDelete`), `payer` json nullable, `pdf_disk` nullable, `pdf_path` nullable. Table `payment_document_sequences(id, series string, year unsignedSmallInteger, last_number unsignedInteger, timestamps, unique(series, year))`. `DocumentNumberer::next(string $series, CarbonInterface $issuedAt): string` — must be called inside the caller's transaction on the tenant connection. Config `local.credit_note_number_prefix` (`PAYMENTS_CREDIT_NOTE_PREFIX`, `CN`).

- [ ] **Step 1:** Migrations and model (`UsesTenantConnection`). `PaymentInvoice` gets casts for `payer` (array), relation `creditNotes()` (hasMany on `credits_invoice_id`) and `creditedInvoice()`.
- [ ] **Step 2:** `DocumentNumberer::next()`: `firstOrCreate` the `(series, year)` row, re-select it with `lockForUpdate()`, increment `last_number`, return `sprintf('%s-%d-%s', $series, $year, str_pad((string) $n, 4, '0', STR_PAD_LEFT))` (no truncation above 9999).
- [ ] **Step 3:** `LocalInvoiceDriver::issue()` uses `DocumentNumberer` with the invoice prefix and stores `payer` = `$data->customer?->toArray()` merged with `payerName`/`payerEmail` fallbacks. Remove the `LIKE`/`max()` lookup.
- [ ] **Step 4: Verify** — `docker compose exec -T php php artisan tenants:migrate --tenants=net-os` → both migrations run. Tinker: set the sequence row for `INV`/2026 to `last_number = 9999`, issue a local invoice → number `INV-2026-10000`; issue again → `INV-2026-10001`. Reset the row afterwards.
- [ ] **Step 5: Commit** `feat: gapless document numbering per series and year`.

### Task 3: Credit notes for both sources and status parity

**Files:**
- Modify: `src/Contracts/ProvidesInvoices.php`, `src/Drivers/Local/LocalInvoiceDriver.php`, `src/Drivers/Stripe/StripeInvoiceDriver.php`, `src/Data/InvoiceData.php`

**Interfaces:**
- Consumes: `DocumentNumberer` (Task 2).
- Produces: `ProvidesInvoices::creditNote(PaymentContext $context, string $invoiceReference, int $amount, ?string $providerRefundId = null): InvoiceData` — returns the **credit note**. `InvoiceData` gets `string $type = 'invoice'` and `?string $creditsReference = null`. Invoice statuses for both drivers: `issued` (local) / `paid` (Stripe) → `partially_credited` → `credited`.

- [ ] **Step 1: Local** — inside a tenant transaction, lock the invoice, compute remaining = `total - credited_amount`; throw `InvalidArgumentException('Credit exceeds the remaining amount.')` when `$amount > remaining` or `$amount <= 0`. Create a `credit_note` row (number from series `CN`, `credits_invoice_id`, issuer/payer copied, `total = -$amount`, lines: one line per VAT rate of the invoice, `"Refund for invoice {number}"`, amounts split proportionally to the invoice lines' gross amounts with the last line taking the rounding remainder, tax per line with the same inclusive formula as `LocalTaxDriver`). Update the invoice `credited_amount`, `credited_at`, status `credited` when remaining becomes 0, else `partially_credited`.
- [ ] **Step 2: Stripe** — `creditNote()` calls `$this->stripe->creditNotes->create(['invoice' => $ref, 'amount' => $amount] + ($providerRefundId ? ['refunds' => [['refund' => $providerRefundId]]] : []), ['stripe_account' => ...])` (check the exact `refunds` parameter shape against stripe-php 22 / API docs before coding) and returns `InvoiceData` with `type: 'credit_note'`, number, `pdfUrl` = the credit note's `pdf`, `hostedUrl` null, `creditsReference` = invoice id. `find()` maps status: `post_payment_credit_notes_amount >= total` → `credited`, `> 0` → `partially_credited`, else Stripe's status.
- [ ] **Step 3: Verify** — local: tinker on an issued €30,00 invoice: `creditNote(ctx, id, 1000)` → CN with total -1000, invoice `partially_credited`; `creditNote(ctx, id, 2500)` → exception; `creditNote(ctx, id, 2000)` → invoice `credited`. Stripe (sandbox payment with invoice): refund via Net OS UI after Task 8 → Stripe dashboard shows one refund and one credit note.
- [ ] **Step 4: Commit** `feat: credit notes as documents for local and Stripe invoices`.

### Task 4: PDF, signed document routes and the issued event

**Files:**
- Create: `resources/views/invoices/document.blade.php`, `resources/views/invoices/page.blade.php`, `src/Jobs/RenderInvoicePdfJob.php`, `src/Events/PaymentInvoiceIssued.php`, `src/Http/Controllers/DocumentController.php`, `routes/documents.php`
- Modify: `composer.json` (require `spatie/laravel-pdf:^2.0`), `src/PaymentsServiceProvider.php`, `src/PaymentContext.php`, `src/Drivers/Local/LocalInvoiceDriver.php`, `src/Drivers/Stripe/StripeInvoiceDriver.php`, `config/payments.php`

**Interfaces:**
- Consumes: credit notes and columns (Tasks 2–3).
- Produces: `PaymentContext::$urlParameters` (`array<string, string>`, last constructor param, default `[]`, carried by every `with*()`); `PaymentInvoiceIssued(InvoiceData $document, string $reference, ?string $payerEmail, ?string $pdfDisk, ?string $pdfPath)`; route names `payments.documents.show` and `payments.documents.pdf` (`GET {prefix}/{document}` and `{prefix}/{document}/pdf`, middleware `['signed', ...config('payments.routes.documents_middleware')]`); config `routes.documents_prefix` (`payments/documents`), `routes.documents_middleware` (`[]`), `local.invoice_disk` (`PAYMENTS_INVOICE_DISK`, `local`). Views namespace `payments`, publish tag `payments-views`.

- [ ] **Step 1:** Require `spatie/laravel-pdf` in the package; in Net OS run `docker compose exec -T php composer update net-os/laravel-payments --with-dependencies` and check `composer.lock` adds only `spatie/laravel-pdf` (+ its own deps).
- [ ] **Step 2:** Views: `document` renders one invoice or credit note per the spec §3 field list (tables only, no flex/grid); `page` wraps it with a "Download PDF" link.
- [ ] **Step 3:** `RenderInvoicePdfJob(int $invoiceId, array $urlParameters)` on `config('payments.queue')`: `Pdf::view('payments::invoices.document', ...)->driver('cloudflare')->disk($disk)->save("invoices/{year}/{number}.pdf")`, store `pdf_disk`/`pdf_path`, then dispatch `PaymentInvoiceIssued`. `LocalInvoiceDriver::issue()` and `creditNote()` dispatch it after commit (`afterCommit()`); the tenancy queue bootstrapper restores the tenant.
- [ ] **Step 4:** `LocalInvoiceDriver::toData()` returns `hostedUrl`/`pdfUrl` via `URL::signedRoute(name, ['document' => $id] + $context->urlParameters)` (no expiry); `pdfUrl` null until `pdf_path` is set. Pass the context into `toData()`.
- [ ] **Step 5:** `DocumentController` (invokable per route or `show`/`pdf` methods): `show` returns the page view; `pdf` streams from `pdf_disk` with `Content-Type: application/pdf`, 404 when `pdf_path` is null.
- [ ] **Step 6:** `StripeInvoiceDriver::creditNote()` dispatches `PaymentInvoiceIssued` with `pdfDisk`/`pdfPath` null (the host attaches nothing and links to `pdfUrl`).
- [ ] **Step 7: Verify** — after Task 8 wiring: issue a local invoice → worker log shows the job; `hostedUrl` opens the page (200); `pdfUrl` returns a PDF that opens; before the job ran `pdf` gives 404; changing one character of `signature` gives 403; swapping the `tenant` parameter on a valid link gives 403 (signature covers it).
- [ ] **Step 8: Commit** `feat: render local documents to PDF and serve them on signed routes`.

### Task 5: Discount code reservations and per-source lists

**Files:**
- Create: `database/migrations/tenant/2026_11_13_000003_add_reservation_columns_to_payment_discount_codes_table.php`
- Modify: `src/Contracts/ManagesDiscountCodes.php`, `src/Drivers/Local/LocalDiscountCodeDriver.php`, `src/Drivers/Stripe/StripeDiscountCodeDriver.php`, `src/Drivers/Concerns/ValidatesDiscountCodes.php`, `src/Models/PaymentDiscountCode.php`, `src/Enums/DiscountCodeStatus.php`

**Interfaces:**
- Produces: columns `reserved_reference` nullable string, `reserved_until` nullable timestamp. Contract: `reserve(PaymentContext $context, PaymentDiscountCode $code, string $reference, CarbonInterface $until): bool` and `release(PaymentContext $context, PaymentDiscountCode $code, string $reference): void`. New status `RESERVED` with reason `'This code is already in use.'`. `validate(PaymentContext $context, string $code, ?string $reference = null)` ignores a reservation held by `$reference`.

- [ ] **Step 1:** Migration, casts, status: `RESERVED` when type `ONE_TIME`, not redeemed, `reserved_until` in the future (checked before `ACTIVE`).
- [ ] **Step 2: Local** `reserve()`: one conditional `UPDATE ... WHERE id = ? AND times_redeemed = 0 AND (reserved_until IS NULL OR reserved_until < now() OR reserved_reference = ?)` returning affected rows > 0; long-term codes always return true without writing. `release()` clears only when `reserved_reference` matches. `redeem()` also clears the reservation. **Stripe:** `reserve()` returns true, `release()` no-op.
- [ ] **Step 3:** `list()` filters `where('source', $context->sources->discounts)` and, for Stripe, `where('provider_account_id', $context->providerAccountId)` (check the column name on the model).
- [ ] **Step 4: Verify** — after Task 8 wiring: two browser tabs, same one-time local code: tab 1 "Confirm & pay" (stay on Stripe); tab 2 shows "This code is already in use." and cannot pay; cancel tab 1's checkout → tab 2's quote accepts the code.
- [ ] **Step 5: Commit** `feat: reserve one-time discount codes during checkout`.

### Task 6: Package docs

**Files:** Modify `README.md`, `docs/Invoices.md`, `docs/Discount-Codes.md`, `docs/Configuration.md`, `docs/Data-Model.md`, `docs/Webhooks.md` (events), `docs/Security-and-Limitations.md` (remove fixed bugs).

- [ ] **Step 1:** Document the new config keys, columns/tables, contract changes (`creditNote` signature, `reserve`/`release`, `validate` reference), `PaymentContext::$urlParameters`, document routes, `PaymentInvoiceIssued`, the PDF job and Cloudflare requirement.
- [ ] **Step 2: Commit** `docs: local invoices, credit notes and reservations`.

### Task 7: Tenant billing profile in Net OS

**Files (Net OS `laravel/server` unless noted):**
- Create: `app/Data/Payments/BillingProfileData.php`, `app/Actions/Payments/Settings/GetBillingProfileAction.php`, `app/Actions/Payments/Settings/UpdateBillingProfileAction.php`, `app/Http/Controllers/Payments/BillingProfileController.php`, `ui/api/payments/billing-profile-query.ts`, `ui/api/payments/update-billing-profile-mutation.ts`, `ui/components/OperatorAdmin/PaymentIntegration/BillingProfileCard.tsx`
- Modify: `routes/api.php`, `app/Models/Organisation/Tenant.php` (docblock `billing_profile`), `app/Services/Payments/TenantIssuerProvider.php`, `app/Actions/Payments/Accounts/CheckPaymentAccountConfigurationAction.php`, `ui/api/payments/index.d.ts`, `ui/app/(OperatorAdmin)/TabStack/profile/payment-integration.tsx`

**Interfaces:**
- Produces: `BillingProfileData(string $legalName, string $street, string $postalCode, string $city, string $country, string $chamberOfCommerceNumber, string $vatNumber)` with validation: KvK `regex:/^\d{8}$/`, VAT `regex:/^NL\d{9}B\d{2}$/` when country is `NL`, country ISO-2. Stored at tenant `data.billing_profile`. Routes `GET|PUT payments/billing-profile` (names `payments.billing-profile.show|update`) behind `AuthorizesPayAsYouGo`. `TenantIssuerProvider::issuer()` returns `IssuerData(name: legalName, address: "street\npostal city\ncountry", chamberOfCommerceNumber, vatNumber)`; when no profile, `IssuerData(name: tenant name)` as today. Configuration check row `billing_profile` (ok / missing, not auto-fixable).

- [ ] **Step 1:** Backend (data, actions, controller with `show`/`update`, routes, issuer provider, config check).
- [ ] **Step 2:** UI card on Payment integration: view mode with the fields, edit with `useAppForm` + zod mirroring the backend rules, react-query invalidation of the configuration query. Reuse existing card/inputs in `components/OperatorAdmin/PaymentIntegration`.
- [ ] **Step 3: Verify** — browser (operator, desktop): fill the card with a valid profile → saved, reload shows it, config check row green; invalid VAT `NL123` → field error from the server. `npx tsc --noEmit` in `ui` passes.

### Task 8: Net OS wiring

**Files (Net OS):**
- Create: `app/Listeners/Payments/SendGuestDocumentMailOnInvoiceIssued.php`, `app/Mail/Payments/GuestDocumentMail.php`, `resources/views/mail/payments/guest-document.blade.php`
- Modify: `config/payments.php` (publish/merge `local.invoice_disk` → `env('PAYMENTS_INVOICE_DISK', 'gcs')`, `routes.documents_middleware` → Net OS's tenant-by-request-data middleware), `app/Services/Payments/TenantPaymentContextResolver.php` (`urlParameters: ['tenant' => tenant id]`), `app/Actions/Payments/Invoices/IssueLocalInvoiceAction.php` (pass `customer`; throw `RuntimeException('Complete the billing profile before issuing local invoices.')` when the tenant has no complete `billing_profile`), `app/Actions/Payments/Guests/SyncGuestPaymentAction.php` (pass checkout customer to issue; wrap issuing in `rescue()` + log so payment sync never fails — Review Focus 4), `app/Actions/Payments/Invoices/RefundPaymentAction.php` (call `creditNote()` for both sources with the refund id; do not overwrite the payment's invoice fields with the credit note), `app/Actions/Payments/Guests/CreateGuestBookingAction.php` (reserve the code with reference = payment uuid until checkout expiry; fail with the reason when `reserve()` is false), `ReleaseGuestBookingAction` (release), `GetGuestQuoteAction` (validate with the payment reference when present), `.env.example` (`LARAVEL_PDF_DRIVER=cloudflare`, `CLOUDFLARE_API_TOKEN=`, `CLOUDFLARE_ACCOUNT_ID=`, `PAYMENTS_INVOICE_DISK=gcs`), local `.env` `MAIL_MAILER=log` (not committed), event registration.

**Interfaces:**
- Consumes: everything above.
- Produces: `GuestDocumentMail(InvoiceData $document, ?string $pdfDisk, ?string $pdfPath)` — subject `"Your invoice {number}"` or `"Credit note {number}"`, body with the hosted link (or PDF link for Stripe credit notes), attachment from the disk when a path is set.

- [ ] **Step 1:** Implement the modifications above.
- [ ] **Step 2: Verify (end to end, net-os tenant on "Locally managed", billing profile filled, Stripe connected):** guest books desk 37 with a local one-time code and pays with a test card → payment `paid`, local invoice `INV-2026-0001` with the guest's company/address from Checkout, `storage/logs/laravel.log` contains the invoice mail with a PDF attachment, operator Invoices screen opens the hosted page and PDF. Refund €10 from the operator screen → CN-2026-0001 (-€10) mailed, invoice `partially_credited`. Refund the rest → second CN, invoice `credited`. Repeat the payment on the "Stripe managed" profile and refund → Stripe credit note created and mailed by Net OS.
- [ ] **Step 3:** Remove the local-only branch comment "Stripe leaves its invoice as it is" and anything made dead by these changes.

### Task 9: Wiki sync

- [ ] **Step 1:** Copy the changed `docs/*.md` to `~/Code/laravel-payments.wiki`, convert `](Page.md` links to `](Page`, commit `Wiki: local invoices, credit notes and reservations`. Do not push; ask the user.
