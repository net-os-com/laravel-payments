<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * What the platform sets on a new account. Support details and the statement descriptor can only be set before the
 * account holder completes onboarding; branding and locales stay writable (updateBranding(), updateLocales()).
 */
class AccountDefaultsData extends Data
{
    /**
     * @param list<string> $locales E.g. nl-NL; the language of the provider's emails and invoices.
     */
    public function __construct(
        public ?string $statementDescriptor = null,
        public ?string $supportEmail = null,
        public ?string $supportPhone = null,
        public ?string $supportUrl = null,
        public array $locales = [],
        public ?BrandingData $branding = null,
    ) {}
}
