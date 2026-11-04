<?php

namespace NetOS\Payments\Data;

use NetOS\Payments\Enums\Provider;
use Spatie\LaravelData\Data;

/**
 * A verified inbound webhook event, before it is processed.
 */
class ProviderEventData extends Data
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public Provider $provider,
        public string $providerEventId,
        public string $type,
        public ?string $providerAccountId,
        public array $payload,
    ) {}
}
