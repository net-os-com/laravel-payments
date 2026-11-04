<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class TaxableLineData extends Data
{
    public function __construct(
        public string $description,
        /** Minor units, incl. tax. */
        public int $amount,
        /** What is sold, e.g. workspace_rental; picks the rate. */
        public string $category,
    ) {}
}
