<?php

namespace NetOS\Payments\Facades;

use Illuminate\Support\Facades\Facade;
use NetOS\Payments\Contracts\CalculatesTax;
use NetOS\Payments\Contracts\IssuesRefunds;
use NetOS\Payments\Contracts\ManagesAccounts;
use NetOS\Payments\Contracts\ManagesDiscountCodes;
use NetOS\Payments\Contracts\ProcessesCheckouts;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Contracts\TranslatesWebhooks;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\PaymentContext;

/**
 * @method static ManagesAccounts accounts(PaymentContext $context)
 * @method static ProcessesCheckouts checkouts(PaymentContext $context)
 * @method static IssuesRefunds refunds(PaymentContext $context)
 * @method static ProvidesInvoices invoices(PaymentContext $context)
 * @method static CalculatesTax tax(PaymentContext $context)
 * @method static ManagesDiscountCodes discounts(PaymentContext $context)
 * @method static TranslatesWebhooks webhooks(Provider $provider = Provider::STRIPE)
 * @see \NetOS\Payments\Payments
 */
class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NetOS\Payments\Payments::class;
    }
}
