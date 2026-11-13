<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * The billing details the customer entered at the provider's checkout, as printed on an invoice.
 */
class CustomerDetailsData extends Data
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $companyName = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        /** ISO 3166-1 alpha-2. */
        public ?string $country = null,
        /** The provider's tax id type, e.g. "eu_vat". */
        public ?string $taxIdType = null,
        public ?string $taxIdValue = null,
    ) {}
}
