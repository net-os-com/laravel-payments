<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class CheckoutLineItemData extends Data
{
    public function __construct(
        public string $description,
        /** Minor units, incl. tax. */
        public int $unitAmount,
        public int $quantity = 1,
        /** Inclusive tax rate when tax is calculated locally; null when the provider calculates it. */
        public ?float $taxPercentage = null,
    ) {}
}
