<?php

namespace NetOS\Payments\Data;

use InvalidArgumentException;
use NetOS\Payments\Enums\CapabilitySource;
use Spatie\LaravelData\Data;

/**
 * Where invoicing, tax and discount codes run for one account. Payments themselves always run at the provider.
 */
class CapabilitySourcesData extends Data
{
    public function __construct(
        public CapabilitySource $invoicing,
        public CapabilitySource $tax,
        public CapabilitySource $discounts,
    ) {}

    /**
     * A local invoice cannot depend on amounts the provider calculates inside its checkout.
     */
    public function problem(): ?string
    {
        if ($this->invoicing !== CapabilitySource::LOCAL) {
            return null;
        }

        return $this->tax === CapabilitySource::STRIPE || $this->discounts === CapabilitySource::STRIPE
            ? 'Local invoicing needs local tax and local discount codes.'
            : null;
    }

    public function assertAllowed(): self
    {
        $problem = $this->problem();

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return $this;
    }
}
