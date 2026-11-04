<?php

namespace NetOS\Payments\Data;

use Carbon\CarbonImmutable;
use NetOS\Payments\Enums\DiscountCodeType;
use Spatie\LaravelData\Data;

class CreateDiscountCodeData extends Data
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public DiscountCodeType $type,
        public string $name,
        public ?string $description,
        /** What the customer types; stored in capitals. */
        public string $code,
        public int $percentOff,
        public CarbonImmutable $validFrom,
        /** Inclusive; null means no end date. */
        public ?CarbonImmutable $validUntil,
        public ?int $createdBy = null,
        public array $metadata = [],
    ) {}
}
