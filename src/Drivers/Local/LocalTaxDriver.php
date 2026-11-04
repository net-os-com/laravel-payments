<?php

namespace NetOS\Payments\Drivers\Local;

use NetOS\Payments\Contracts\CalculatesTax;
use NetOS\Payments\Data\TaxableLineData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\Models\PaymentTaxRate;
use NetOS\Payments\PaymentContext;
use RuntimeException;

/**
 * Inclusive VAT from the rate table, per line, rounded per line.
 */
class LocalTaxDriver implements CalculatesTax
{
    public function __construct(
        private readonly string $country,
    ) {}

    public function isHandledByCheckout(): bool
    {
        return false;
    }

    public function calculate(PaymentContext $context, array $lines): array
    {
        $rates = [];

        return array_map(function (TaxableLineData $line) use (&$rates): TaxedLineData {
            $rate = $rates[$line->category] ??= $this->rateFor($line->category);

            return new TaxedLineData(
                description: $line->description,
                amount: $line->amount,
                taxPercentage: $rate,
                taxAmount: $line->amount - (int) round($line->amount * 100 / (100 + $rate)),
            );
        }, $lines);
    }

    private function rateFor(string $category): float
    {
        $rate = PaymentTaxRate::query()
            ->where('country', mb_strtoupper($this->country))
            ->where('category', $category)
            ->validOn(now())
            ->latest('valid_from')
            ->value('rate');

        return $rate === null
            ? throw new RuntimeException(sprintf('No tax rate for %s in %s.', $category, mb_strtoupper($this->country)))
            : (float) $rate;
    }
}
