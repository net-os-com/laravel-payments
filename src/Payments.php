<?php

namespace NetOS\Payments;

use NetOS\Payments\Contracts\CalculatesTax;
use NetOS\Payments\Contracts\IssuesRefunds;
use NetOS\Payments\Contracts\ManagesAccounts;
use NetOS\Payments\Contracts\ManagesDiscountCodes;
use NetOS\Payments\Contracts\ProcessesCheckouts;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Contracts\TranslatesWebhooks;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Managers\AccountManager;
use NetOS\Payments\Managers\CheckoutManager;
use NetOS\Payments\Managers\DiscountCodeManager;
use NetOS\Payments\Managers\InvoiceManager;
use NetOS\Payments\Managers\RefundManager;
use NetOS\Payments\Managers\TaxManager;
use NetOS\Payments\Managers\WebhookManager;

/**
 * The entry point of the package: one manager per capability. Payment capabilities follow the context's provider;
 * invoicing, tax and discount codes follow the context's source for that capability (stripe or local).
 */
class Payments
{
    public function __construct(
        private readonly AccountManager $accounts,
        private readonly CheckoutManager $checkouts,
        private readonly RefundManager $refunds,
        private readonly InvoiceManager $invoices,
        private readonly TaxManager $tax,
        private readonly DiscountCodeManager $discountCodes,
        private readonly WebhookManager $webhooks,
    ) {}

    public function accounts(PaymentContext $context): ManagesAccounts
    {
        return $this->accounts->driver($context->provider->value);
    }

    public function checkouts(PaymentContext $context): ProcessesCheckouts
    {
        return $this->checkouts->driver($context->provider->value);
    }

    public function refunds(PaymentContext $context): IssuesRefunds
    {
        return $this->refunds->driver($context->provider->value);
    }

    public function invoices(PaymentContext $context): ProvidesInvoices
    {
        return $this->invoices->driver($context->sources->invoicing->value);
    }

    public function tax(PaymentContext $context): CalculatesTax
    {
        return $this->tax->driver($context->sources->tax->value);
    }

    public function discounts(PaymentContext $context): ManagesDiscountCodes
    {
        return $this->discountCodes->driver($context->sources->discounts->value);
    }

    public function webhooks(Provider $provider = Provider::STRIPE): TranslatesWebhooks
    {
        return $this->webhooks->driver($provider->value);
    }
}
