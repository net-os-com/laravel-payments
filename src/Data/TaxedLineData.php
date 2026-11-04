<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class TaxedLineData extends Data
{
    public function __construct(
        public string $description,
        /** Minor units, incl. tax. */
        public int $amount,
        public float $taxPercentage,
        public int $taxAmount,
    ) {}
}
