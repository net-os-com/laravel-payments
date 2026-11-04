<?php

namespace NetOS\Payments\Data;

use NetOS\Payments\Enums\PaymentAccountStatus;
use Spatie\LaravelData\Data;

/**
 * An account as the provider reports it.
 */
class ProviderAccountData extends Data
{
    /**
     * @param array<string, string> $capabilities Capability name => provider status, e.g. ['payments' => 'active']
     * @param list<string> $requirementsDue What the account holder still has to provide
     */
    public function __construct(
        public string $providerAccountId,
        public PaymentAccountStatus $status,
        public ?string $businessName,
        public ?string $email,
        public array $capabilities,
        public array $requirementsDue,
    ) {}
}
