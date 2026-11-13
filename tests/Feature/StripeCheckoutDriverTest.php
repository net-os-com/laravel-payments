<?php

use Carbon\CarbonImmutable;
use NetOS\Payments\Data\CheckoutLineItemData;
use NetOS\Payments\Data\CreateCheckoutData;
use NetOS\Payments\Enums\CheckoutStatus;
use NetOS\Payments\Enums\PaymentProfile;
use NetOS\Payments\Facades\Payments;

function stripeSession(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
        'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 2400,
        'total_details' => ['amount_discount' => 0, 'amount_tax' => 417],
        'payment_intent' => null, 'invoice' => null, 'customer_details' => null, 'metadata' => ['payment_uuid' => 'p-1'],
    ], $overrides);
}

function checkoutData(array $overrides = []): CreateCheckoutData
{
    return new CreateCheckoutData(
        lineItems: $overrides['lineItems'] ?? [new CheckoutLineItemData('Desk', 2400, taxPercentage: 21.0)],
        currency: 'eur',
        customerEmail: 'guest@example.com',
        successUrl: 'https://app.example/success',
        cancelUrl: 'https://app.example/cancel',
        expiresAt: $overrides['expiresAt'] ?? CarbonImmutable::now()->addMinutes(31),
        providerDiscountId: $overrides['providerDiscountId'] ?? null,
        description: 'Desk booking',
        metadata: ['payment_uuid' => 'p-1'],
    );
}

beforeEach(function (): void {
    $this->stripe->on('POST /v1/checkout/sessions', stripeSession());
    $this->stripe->on('GET /v1/tax_rates', ['object' => 'list', 'url' => '/v1/tax_rates', 'has_more' => false, 'data' => []]);
    $this->stripe->on('POST /v1/tax_rates', ['id' => 'txr_21', 'object' => 'tax_rate', 'percentage' => 21, 'country' => 'NL']);
});

it('asks every source for billing details so local invoices can print them', function (PaymentProfile $profile): void {
    $ctx = $this->context($profile);

    Payments::checkouts($ctx)->create($ctx, checkoutData());

    $params = $this->stripe->last('POST', '/v1/checkout/sessions')['params'];
    expect($params['billing_address_collection'])->toBe('required')
        ->and($params['tax_id_collection'])->toBe(['enabled' => 'true'])
        ->and($params['customer_creation'])->toBe('always')
        ->and($params['payment_intent_data']['receipt_email'])->toBe('guest@example.com')
        ->and($params)->not->toHaveKey('allow_promotion_codes');
})->with(PaymentProfile::cases());

it('lets Stripe invoice and calculate tax when Stripe manages them', function (): void {
    $ctx = $this->stripeContext();

    Payments::checkouts($ctx)->create($ctx, checkoutData(['providerDiscountId' => 'promo_1']));

    $params = $this->stripe->last('POST', '/v1/checkout/sessions')['params'];
    expect($params['invoice_creation']['enabled'])->toBe('true')
        ->and($params['automatic_tax'])->toBe(['enabled' => 'true'])
        ->and($params['line_items'][0])->not->toHaveKey('tax_rates')
        ->and($params['discounts'])->toBe([['promotion_code' => 'promo_1']])
        ->and($params['stripe_account'] ?? null)->toBeNull()
        ->and($this->stripe->last('POST', '/v1/checkout/sessions')['headers'])->toContain('Stripe-Account: acct_test');
});

it('uses the application tax as inclusive rates and leaves invoicing to the application', function (): void {
    $ctx = $this->localContext();

    Payments::checkouts($ctx)->create($ctx, checkoutData(['providerDiscountId' => 'promo_ignored']));

    $params = $this->stripe->last('POST', '/v1/checkout/sessions')['params'];
    expect($params['line_items'][0]['tax_rates'])->toBe(['txr_21'])
        ->and($params['line_items'][0]['price_data']['tax_behavior'])->toBe('inclusive')
        ->and($params)->not->toHaveKey('invoice_creation')
        ->and($params)->not->toHaveKey('automatic_tax')
        ->and($params)->not->toHaveKey('discounts');
});

it('refuses an expiry Stripe does not accept', function (int $minutes): void {
    $ctx = $this->localContext();

    Payments::checkouts($ctx)->create($ctx, checkoutData(['expiresAt' => CarbonImmutable::now()->addMinutes($minutes)]));
})->with([10, 25 * 60])->throws(InvalidArgumentException::class, 'between 30 minutes and 24 hours');

it('refuses a line without tax percentage under the application tax', function (): void {
    $ctx = $this->localContext();

    Payments::checkouts($ctx)->create($ctx, checkoutData(['lineItems' => [new CheckoutLineItemData('Desk', 2400)]]));
})->throws(InvalidArgumentException::class, 'Line "Desk" has no tax percentage.');

it('maps a paid session with the billing details the customer entered', function (): void {
    $this->stripe->on('GET /v1/checkout/sessions/*', stripeSession([
        'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_1', 'invoice' => 'in_1',
        'customer_details' => [
            'name' => 'Jane', 'email' => 'jane@example.com', 'business_name' => 'Acme B.V.',
            'address' => ['line1' => 'Street 5', 'line2' => null, 'postal_code' => '1234 AB', 'city' => 'Utrecht', 'country' => 'NL'],
            'tax_ids' => [['type' => 'eu_vat', 'value' => 'NL000099998B57']],
        ],
    ]));
    $ctx = $this->stripeContext();

    $checkout = Payments::checkouts($ctx)->fetch($ctx, 'cs_test_1');

    expect($checkout->status)->toBe(CheckoutStatus::PAID)
        ->and($checkout->providerPaymentId)->toBe('pi_1')
        ->and($checkout->providerInvoiceId)->toBe('in_1')
        ->and($checkout->customer?->companyName)->toBe('Acme B.V.')
        ->and($checkout->customer?->city)->toBe('Utrecht')
        ->and($checkout->customer?->taxIdType)->toBe('eu_vat')
        ->and($checkout->customer?->taxIdValue)->toBe('NL000099998B57');
});

it('maps an expired session', function (): void {
    $this->stripe->on('POST /v1/checkout/sessions/*/expire', stripeSession(['status' => 'expired']));
    $ctx = $this->stripeContext();

    expect(Payments::checkouts($ctx)->expire($ctx, 'cs_test_1')->status)->toBe(CheckoutStatus::EXPIRED);
});
