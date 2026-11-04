<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * What the provider has set on the account now.
 */
class AccountConfigurationData extends Data
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        public ?string $displayName,
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public bool $hasLogo,
        public bool $hasIcon,
        public ?string $statementDescriptor,
        public ?string $supportEmail,
        public ?string $supportPhone,
        public ?string $supportUrl,
        public array $locales,
        public ?string $currency,
    ) {}
}
