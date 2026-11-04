<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\TaxableLineData;
use NetOS\Payments\Data\TaxedLineData;
use NetOS\Payments\PaymentContext;

interface CalculatesTax
{
    /**
     * True when the provider calculates tax inside its checkout; the application then cannot know it beforehand.
     */
    public function isHandledByCheckout(): bool;

    /**
     * @param array<TaxableLineData> $lines amounts include tax
     * @return array<TaxedLineData>
     */
    public function calculate(PaymentContext $context, array $lines): array;
}
