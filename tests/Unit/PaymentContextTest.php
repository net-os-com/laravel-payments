<?php

use NetOS\Payments\Data\CapabilitySourcesData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\PaymentProfile;
use NetOS\Payments\Exceptions\AccountNotConnectedException;

it('keeps the issuer and url parameters when sources or the account change', function (): void {
    $context = $this->context()
        ->withAccount('acct_other')
        ->withDiscountSource(CapabilitySource::LOCAL)
        ->withInvoicingSource(CapabilitySource::LOCAL);

    expect($context->providerAccountId)->toBe('acct_other')
        ->and($context->urlParameters)->toBe(['tenant' => 'acme'])
        ->and($context->issuer?->name)->toBe('Operator B.V.')
        ->and($context->sources->invoicing)->toBe(CapabilitySource::LOCAL);
});

it('requires a connected account for provider calls', function (): void {
    $this->context(accountId: null)->requireAccount();
})->throws(AccountNotConnectedException::class);

it('only allows local invoicing together with local tax and local discount codes', function (
    CapabilitySource $invoicing,
    CapabilitySource $tax,
    CapabilitySource $discounts,
    bool $isAllowed,
): void {
    $sources = new CapabilitySourcesData($invoicing, $tax, $discounts);

    expect($sources->problem() === null)->toBe($isAllowed);
})->with([
    'stripe managed' => [CapabilitySource::STRIPE, CapabilitySource::STRIPE, CapabilitySource::STRIPE, true],
    'stripe with local discounts' => [CapabilitySource::STRIPE, CapabilitySource::STRIPE, CapabilitySource::LOCAL, true],
    'stripe invoicing, local tax' => [CapabilitySource::STRIPE, CapabilitySource::LOCAL, CapabilitySource::STRIPE, true],
    'locally managed' => [CapabilitySource::LOCAL, CapabilitySource::LOCAL, CapabilitySource::LOCAL, true],
    'local invoicing, stripe tax' => [CapabilitySource::LOCAL, CapabilitySource::STRIPE, CapabilitySource::LOCAL, false],
    'local invoicing, stripe discounts' => [CapabilitySource::LOCAL, CapabilitySource::LOCAL, CapabilitySource::STRIPE, false],
]);

it('exposes every profile as an allowed combination', function (PaymentProfile $profile): void {
    expect($profile->sources()->problem())->toBeNull();
})->with(PaymentProfile::cases());
