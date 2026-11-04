<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class ProviderRefundData extends Data
{
    public function __construct(
        public string $providerRefundId,
        /** Minor units. */
        public int $amount,
        /** E.g. succeeded, pending or failed. */
        public string $status,
    ) {}
}
