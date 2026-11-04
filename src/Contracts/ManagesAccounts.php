<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\AccountConfigurationData;
use NetOS\Payments\Data\BrandingData;
use NetOS\Payments\Data\CreateAccountData;
use NetOS\Payments\Data\ProviderAccountData;
use NetOS\Payments\PaymentContext;

interface ManagesAccounts
{
    public function create(PaymentContext $context, CreateAccountData $data): ProviderAccountData;

    public function retrieve(PaymentContext $context): ProviderAccountData;

    /**
     * A single-use URL where the account holder completes the provider's onboarding.
     */
    public function onboardingUrl(PaymentContext $context, string $returnUrl, string $refreshUrl): string;

    /**
     * What the provider has set on the account now: branding, support details, descriptor and locales.
     */
    public function configuration(PaymentContext $context): AccountConfigurationData;

    /**
     * Branding stays writable after onboarding; a logo is uploaded to the provider first.
     */
    public function updateBranding(PaymentContext $context, BrandingData $branding): AccountConfigurationData;

    /**
     * The language of the provider's emails and invoices; stays writable after onboarding.
     *
     * @param list<string> $locales
     */
    public function updateLocales(PaymentContext $context, array $locales): AccountConfigurationData;

    /**
     * Where the account holder manages the account at the provider.
     */
    public function dashboardUrl(PaymentContext $context): string;
}
