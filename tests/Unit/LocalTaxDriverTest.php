<?php

use NetOS\Payments\Data\TaxableLineData;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentTaxRate;

beforeEach(function (): void {
    PaymentTaxRate::query()->create(['country' => 'NL', 'category' => 'workspace_rental', 'rate' => 21, 'valid_from' => '2020-01-01']);
    PaymentTaxRate::query()->create(['country' => 'NL', 'category' => 'catering', 'rate' => 9, 'valid_from' => '2020-01-01']);
});

it('calculates inclusive VAT per line from the rate table', function (): void {
    $context = $this->localContext();

    $lines = Payments::tax($context)->calculate($context, [
        new TaxableLineData('Desk', 1210, 'workspace_rental'),
        new TaxableLineData('Coffee', 109, 'catering'),
    ]);

    expect($lines[0]->taxPercentage)->toBe(21.0)
        ->and($lines[0]->taxAmount)->toBe(210)
        ->and($lines[1]->taxPercentage)->toBe(9.0)
        ->and($lines[1]->taxAmount)->toBe(9)
        ->and($lines[0]->amount)->toBe(1210);
});

it('refuses a category without a rate instead of charging no VAT', function (): void {
    $context = $this->localContext();

    Payments::tax($context)->calculate($context, [new TaxableLineData('Parking', 500, 'parking')]);
})->throws(RuntimeException::class, 'No tax rate for parking in NL.');

it('uses the newest rate that is valid today', function (): void {
    PaymentTaxRate::query()->create(['country' => 'NL', 'category' => 'workspace_rental', 'rate' => 19, 'valid_from' => now()->subDay()]);
    PaymentTaxRate::query()->create(['country' => 'NL', 'category' => 'workspace_rental', 'rate' => 25, 'valid_from' => now()->addYear()]);
    $context = $this->localContext();

    $lines = Payments::tax($context)->calculate($context, [new TaxableLineData('Desk', 1190, 'workspace_rental')]);

    expect($lines[0]->taxPercentage)->toBe(19.0);
});
