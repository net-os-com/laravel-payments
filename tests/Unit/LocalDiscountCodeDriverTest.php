<?php

use Carbon\CarbonImmutable;
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\DiscountCodeStatus;
use NetOS\Payments\Enums\DiscountCodeType;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentDiscountCode;

beforeEach(function (): void {
    $this->ctx = $this->localContext();
    $this->codes = Payments::discounts($this->ctx);
});

function makeCode(object $test, DiscountCodeType $type = DiscountCodeType::ONE_TIME, array $overrides = []): PaymentDiscountCode
{
    return $test->codes->create($test->ctx, new CreateDiscountCodeData(
        type: $type,
        name: 'Welcome',
        description: null,
        code: $overrides['code'] ?? 'welcome-1',
        percentOff: 20,
        validFrom: $overrides['validFrom'] ?? CarbonImmutable::today(),
        validUntil: $overrides['validUntil'] ?? null,
    ));
}

it('stores codes uppercase and finds them case-insensitively', function (): void {
    makeCode($this);

    expect($this->codes->validate($this->ctx, ' Welcome-1 ')->isValid)->toBeTrue();
    $this->assertDatabaseHas('payment_discount_codes', ['code' => 'WELCOME-1', 'source' => 'local']);
});

it('explains why a code can not be used', function (array $overrides, ?string $reason): void {
    makeCode($this, overrides: $overrides);

    expect($this->codes->validate($this->ctx, 'WELCOME-1')->reason)->toBe($reason);
})->with([
    'active' => [[], null],
    'scheduled' => [['validFrom' => CarbonImmutable::tomorrow()], 'This code is not valid yet.'],
    'expired' => [['validFrom' => CarbonImmutable::today()->subWeek(), 'validUntil' => CarbonImmutable::yesterday()], 'This code is no longer valid.'],
]);

it('reports unknown and inactive codes', function (): void {
    $code = makeCode($this);
    $this->codes->setActive($this->ctx, $code, false);

    expect($this->codes->validate($this->ctx, 'NOPE')->reason)->toBe('This code does not exist.')
        ->and($this->codes->validate($this->ctx, 'WELCOME-1')->reason)->toBe('This code is no longer valid.');
});

it('holds a one-time code for one checkout at a time', function (): void {
    $code = makeCode($this);
    $until = now()->addMinutes(30);

    expect($this->codes->reserve($this->ctx, $code, 'checkout-a', $until))->toBeTrue()
        ->and($this->codes->reserve($this->ctx, $code, 'checkout-b', $until))->toBeFalse()
        ->and($this->codes->validate($this->ctx, 'WELCOME-1', 'checkout-b')->reason)->toBe('This code is already in use.')
        ->and($this->codes->validate($this->ctx, 'WELCOME-1', 'checkout-a')->isValid)->toBeTrue()
        ->and($code->refresh()->status)->toBe(DiscountCodeStatus::RESERVED);

    $this->codes->release($this->ctx, $code, 'checkout-a');

    expect($this->codes->reserve($this->ctx, $code, 'checkout-b', $until))->toBeTrue();
});

it('frees a hold once it expires', function (): void {
    $code = makeCode($this);
    $this->codes->reserve($this->ctx, $code, 'checkout-a', now()->addMinutes(30));
    $this->travel(31)->minutes();

    expect($this->codes->reserve($this->ctx, $code, 'checkout-b', now()->addMinutes(30)))->toBeTrue();
});

it('ignores a release by another checkout', function (): void {
    $code = makeCode($this);
    $this->codes->reserve($this->ctx, $code, 'checkout-a', now()->addMinutes(30));

    $this->codes->release($this->ctx, $code, 'checkout-b');

    expect($code->refresh()->reserved_reference)->toBe('checkout-a');
});

it('marks a redeemed one-time code as used and ends its hold', function (): void {
    $code = makeCode($this);
    $this->codes->reserve($this->ctx, $code, 'checkout-a', now()->addMinutes(30));

    $this->codes->redeem($this->ctx, $code->refresh());

    expect($code->refresh()->status)->toBe(DiscountCodeStatus::USED)
        ->and($code->reserved_reference)->toBeNull()
        ->and($this->codes->reserve($this->ctx, $code, 'checkout-b', now()->addMinutes(30)))->toBeFalse();
});

it('never holds long-term codes', function (): void {
    $code = makeCode($this, DiscountCodeType::LONG_TERM);

    expect($this->codes->reserve($this->ctx, $code, 'checkout-a', now()->addMinutes(30)))->toBeTrue()
        ->and($this->codes->reserve($this->ctx, $code, 'checkout-b', now()->addMinutes(30)))->toBeTrue()
        ->and($code->refresh()->reserved_reference)->toBeNull();
});

it('lists and validates only codes of the active source', function (): void {
    makeCode($this);
    PaymentDiscountCode::query()->create([
        'source' => CapabilitySource::STRIPE, 'name' => 'Stripe', 'code' => 'STRIPE-1', 'type' => DiscountCodeType::LONG_TERM,
        'percent_off' => 10, 'valid_from' => CarbonImmutable::today(), 'provider_account_id' => 'acct_test',
    ]);

    expect($this->codes->list($this->ctx)->pluck('code')->all())->toBe(['WELCOME-1'])
        ->and($this->codes->validate($this->ctx, 'STRIPE-1')->reason)->toBe('This code does not exist.');
});
