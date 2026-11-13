<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Drivers\Stripe\StripeTaxRates;
use NetOS\Payments\Enums\DiscountCodeType;
use NetOS\Payments\Events\PaymentInvoiceIssued;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentDiscountCode;
use Stripe\Exception\InvalidRequestException;

function stripeInvoice(array $overrides = []): array
{
    return array_replace([
        'id' => 'in_1', 'object' => 'invoice', 'number' => 'ABC-0001', 'status' => 'paid', 'total' => 2400,
        'post_payment_credit_notes_amount' => 0, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/1',
        'invoice_pdf' => 'https://pay.stripe.com/invoice/1/pdf', 'customer_email' => 'guest@example.com',
        'metadata' => ['payment_uuid' => 'p-1'],
    ], $overrides);
}

it('reports Stripe invoices with the same statuses as local ones after credits', function (int $credited, string $status): void {
    $this->stripe->on('GET /v1/invoices/in_1', stripeInvoice(['post_payment_credit_notes_amount' => $credited]));
    $ctx = $this->stripeContext();

    expect(Payments::invoices($ctx)->find($ctx, 'in_1')->status)->toBe($status);
})->with([[0, 'paid'], [1000, 'partially_credited'], [2400, 'credited']]);

it('adds a credit note linked to the refund and announces it for delivery', function (): void {
    Event::fake([PaymentInvoiceIssued::class]);
    $this->stripe->on('POST /v1/credit_notes', ['id' => 'cn_1', 'object' => 'credit_note', 'number' => 'ABC-0001-CN-01', 'status' => 'issued', 'pdf' => 'https://pay.stripe.com/cn/1/pdf']);
    $this->stripe->on('GET /v1/invoices/in_1', stripeInvoice());
    $ctx = $this->stripeContext();

    $creditNote = Payments::invoices($ctx)->creditNote($ctx, 'in_1', 1000, 're_1');

    $params = $this->stripe->last('POST', '/v1/credit_notes')['params'];
    expect($params)->toMatchArray(['invoice' => 'in_1', 'amount' => 1000])
        ->and($params['refunds'])->toBe([['refund' => 're_1', 'amount_refunded' => 1000]])
        ->and($creditNote->type)->toBe('credit_note')
        ->and($creditNote->pdfUrl)->toBe('https://pay.stripe.com/cn/1/pdf')
        ->and($creditNote->creditsReference)->toBe('in_1');
    Event::assertDispatched(PaymentInvoiceIssued::class, fn (PaymentInvoiceIssued $event): bool => $event->reference === 'p-1'
        && $event->payerEmail === 'guest@example.com'
        && $event->pdfPath === null);
});

it('does not issue invoices itself: Stripe creates them in Checkout', function (): void {
    $ctx = $this->stripeContext();

    expect(Payments::invoices($ctx)->isHandledByCheckout())->toBeTrue();
    Payments::invoices($ctx)->issue($ctx, new NetOS\Payments\Data\IssueInvoiceData('G', 'g@example.com', [], 0, 'eur', 'r'));
})->throws(NetOS\Payments\Exceptions\HandledByCheckoutException::class);

it('creates a Stripe code as coupon plus promotion code and mirrors it', function (): void {
    $this->stripe->on('POST /v1/coupons', ['id' => 'co_1', 'object' => 'coupon']);
    $this->stripe->on('POST /v1/promotion_codes', ['id' => 'promo_1', 'object' => 'promotion_code', 'times_redeemed' => 0]);
    $ctx = $this->stripeContext();

    $code = Payments::discounts($ctx)->create($ctx, new CreateDiscountCodeData(
        DiscountCodeType::ONE_TIME, 'Welcome', null, 'welcome', 20, CarbonImmutable::today(), null,
    ));

    expect($this->stripe->last('POST', '/v1/promotion_codes')['params'])->toMatchArray(['code' => 'WELCOME', 'max_redemptions' => 1])
        ->and($code->provider_discount_id)->toBe('promo_1')
        ->and($code->provider_account_id)->toBe('acct_test');
});

it('removes the coupon again when the promotion code can not be created', function (): void {
    $this->stripe->on('POST /v1/coupons', ['id' => 'co_1', 'object' => 'coupon']);
    $this->stripe->on('POST /v1/promotion_codes', ['error' => ['type' => 'invalid_request_error', 'message' => 'Code exists']], 400);
    $this->stripe->on('DELETE /v1/coupons/co_1', ['id' => 'co_1', 'object' => 'coupon', 'deleted' => true]);
    $ctx = $this->stripeContext();

    expect(fn () => Payments::discounts($ctx)->create($ctx, new CreateDiscountCodeData(
        DiscountCodeType::ONE_TIME, 'Welcome', null, 'welcome', 20, CarbonImmutable::today(), null,
    )))->toThrow(InvalidRequestException::class);

    expect($this->stripe->count('DELETE', '/v1/coupons/co_1'))->toBe(1)
        ->and(PaymentDiscountCode::query()->count())->toBe(0);
});

it('only validates Stripe codes of the connected account', function (): void {
    PaymentDiscountCode::query()->create([
        'source' => 'stripe', 'name' => 'Old', 'code' => 'OLD', 'type' => DiscountCodeType::LONG_TERM, 'percent_off' => 10,
        'valid_from' => CarbonImmutable::today(), 'provider_account_id' => 'acct_previous',
    ]);
    $ctx = $this->stripeContext();

    expect(Payments::discounts($ctx)->validate($ctx, 'OLD')->reason)->toBe('This code does not exist.')
        ->and(Payments::discounts($ctx)->reserve($ctx, PaymentDiscountCode::query()->sole(), 'r', now()->addHour()))->toBeTrue();
});

it('finds an existing inclusive tax rate on any page and asks Stripe once per rate', function (): void {
    $this->stripe->on('GET /v1/tax_rates', ['object' => 'list', 'url' => '/v1/tax_rates', 'has_more' => true, 'data' => [
        ['id' => 'txr_9', 'object' => 'tax_rate', 'percentage' => 9, 'country' => 'NL'],
    ]]);
    $this->stripe->on('GET /v1/tax_rates', ['object' => 'list', 'url' => '/v1/tax_rates', 'has_more' => false, 'data' => [
        ['id' => 'txr_21', 'object' => 'tax_rate', 'percentage' => 21.0000001, 'country' => 'NL'],
    ]]);
    $rates = new StripeTaxRates(app(Stripe\StripeClient::class), 'nl');

    expect($rates->inclusive('acct_test', 21.0))->toBe('txr_21')
        ->and($rates->inclusive('acct_test', 21.0))->toBe('txr_21')
        ->and($this->stripe->count('GET', '/v1/tax_rates'))->toBe(2)
        ->and($this->stripe->count('POST', '/v1/tax_rates'))->toBe(0);
});

it('refunds the given amount on the connected account', function (): void {
    $this->stripe->on('POST /v1/refunds', ['id' => 're_1', 'object' => 'refund', 'amount' => 1000, 'status' => 'succeeded']);
    $ctx = $this->stripeContext();

    $refund = Payments::refunds($ctx)->refund($ctx, 'pi_1', 1000);

    expect($this->stripe->last('POST', '/v1/refunds')['params'])->toBe(['payment_intent' => 'pi_1', 'amount' => 1000])
        ->and($refund->providerRefundId)->toBe('re_1')
        ->and($refund->amount)->toBe(1000);
});

it('opens the test dashboard for secret and restricted test keys', function (string $key, string $url): void {
    config(['payments.stripe.secret' => $key]);
    app()->forgetInstance(Stripe\StripeClient::class);
    app()->forgetInstance(NetOS\Payments\Managers\AccountManager::class);
    app()->forgetInstance(NetOS\Payments\Payments::class);
    NetOS\Payments\Facades\Payments::clearResolvedInstances();
    $ctx = $this->stripeContext();

    expect(Payments::accounts($ctx)->dashboardUrl($ctx))->toBe($url);
})->with([
    ['sk_test_1', 'https://dashboard.stripe.com/test/dashboard'],
    ['rk_test_1', 'https://dashboard.stripe.com/test/dashboard'],
    ['sk_live_1', 'https://dashboard.stripe.com/dashboard'],
]);

it('refuses to upload a logo from a URL', function (): void {
    $ctx = $this->stripeContext();

    Payments::accounts($ctx)->updateBranding($ctx, new NetOS\Payments\Data\BrandingData(logoPath: 'https://example.com/logo.png'));
})->throws(RuntimeException::class, 'The logo must be a readable local file.');

it('lets an app replace a driver through the manager', function (): void {
    $fake = Mockery::mock(NetOS\Payments\Contracts\CalculatesTax::class);
    app(NetOS\Payments\Managers\TaxManager::class)->extend('local', fn () => $fake);
    $ctx = $this->localContext();

    expect(Payments::tax($ctx))->toBe($fake);
});
