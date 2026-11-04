<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * Who issues local invoices: the merchant's legal details.
 */
class IssuerData extends Data
{
    public function __construct(
        public string $name,
        public ?string $address = null,
        public ?string $chamberOfCommerceNumber = null,
        public ?string $vatNumber = null,
    ) {}
}
