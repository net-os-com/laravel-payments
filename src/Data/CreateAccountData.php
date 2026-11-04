<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class CreateAccountData extends Data
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $displayName,
        public ?string $email,
        public array $metadata = [],
        public ?AccountDefaultsData $defaults = null,
    ) {}
}
