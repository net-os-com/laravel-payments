<?php

namespace NetOS\Payments\Drivers\Stripe;

use NetOS\Payments\Contracts\ManagesAccounts;
use NetOS\Payments\Data\AccountConfigurationData;
use NetOS\Payments\Data\AccountDefaultsData;
use NetOS\Payments\Data\BrandingData;
use NetOS\Payments\Data\CreateAccountData;
use NetOS\Payments\Data\ProviderAccountData;
use NetOS\Payments\Enums\PaymentAccountStatus;
use NetOS\Payments\PaymentContext;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\V2\Core\Account;

/**
 * Connected accounts through the Accounts v2 API: the merchant configuration with a full Stripe dashboard, so the
 * account holder owns the account and Stripe collects fees and covers losses.
 */
class StripeAccountDriver implements ManagesAccounts
{
    private const array INCLUDE = ['configuration.merchant', 'identity', 'requirements'];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $country,
        private readonly string $currency,
    ) {}

    public function create(PaymentContext $context, CreateAccountData $data): ProviderAccountData
    {
        $account = $this->stripe->v2->core->accounts->create(array_replace_recursive([
            'display_name' => $data->displayName,
            'contact_email' => $data->email,
            'dashboard' => 'full',
            'identity' => [
                'country' => $this->country,
            ],
            'configuration' => [
                'merchant' => [
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                    ],
                ],
            ],
            'defaults' => [
                'currency' => $this->currency,
                'responsibilities' => [
                    'fees_collector' => 'stripe',
                    'losses_collector' => 'stripe',
                ],
            ],
            'metadata' => $data->metadata,
            'include' => self::INCLUDE,
        ], $this->defaults($data->defaults)));

        return $this->toData($account);
    }

    public function retrieve(PaymentContext $context): ProviderAccountData
    {
        return $this->toData($this->stripe->v2->core->accounts->retrieve($context->requireAccount(), ['include' => self::INCLUDE]));
    }

    public function onboardingUrl(PaymentContext $context, string $returnUrl, string $refreshUrl): string
    {
        return $this->stripe->v2->core->accountLinks->create([
            'account' => $context->requireAccount(),
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'collection_options' => ['fields' => 'eventually_due'],
                    'return_url' => $returnUrl,
                    'refresh_url' => $refreshUrl,
                ],
            ],
        ])->url;
    }

    public function configuration(PaymentContext $context): AccountConfigurationData
    {
        $account = $this->stripe->v2->core->accounts->retrieve($context->requireAccount(), [
            'include' => ['configuration.merchant', 'defaults'],
        ]);
        $merchant = $account->configuration?->merchant;

        return new AccountConfigurationData(
            displayName: $account->display_name,
            primaryColor: $merchant?->branding?->primary_color,
            secondaryColor: $merchant?->branding?->secondary_color,
            hasLogo: $merchant?->branding?->logo !== null,
            hasIcon: $merchant?->branding?->icon !== null,
            statementDescriptor: $merchant?->statement_descriptor?->descriptor,
            supportEmail: $merchant?->support?->email,
            supportPhone: $merchant?->support?->phone,
            supportUrl: $merchant?->support?->url,
            locales: array_values((array) ($account->defaults?->locales ?? [])),
            currency: $account->defaults?->currency,
        );
    }

    public function updateBranding(PaymentContext $context, BrandingData $branding): AccountConfigurationData
    {
        $accountId = $context->requireAccount();
        $logo = $branding->logoPath === null ? null : $this->uploadLogo($branding->logoPath);

        $this->stripe->v2->core->accounts->update($accountId, [
            'configuration' => ['merchant' => ['branding' => array_filter([
                'primary_color' => $branding->primaryColor,
                'secondary_color' => $branding->secondaryColor,
                'logo' => $logo,
            ], fn (?string $value): bool => $value !== null)]],
        ]);

        return $this->configuration($context);
    }

    public function updateLocales(PaymentContext $context, array $locales): AccountConfigurationData
    {
        $this->stripe->v2->core->accounts->update($context->requireAccount(), ['defaults' => ['locales' => $locales]]);

        return $this->configuration($context);
    }

    public function dashboardUrl(PaymentContext $context): string
    {
        // The account holder signs in to their own dashboard; test accounts open its test mode.
        return str_starts_with($this->stripe->getApiKey() ?? '', 'sk_test_')
            ? 'https://dashboard.stripe.com/test/dashboard'
            : 'https://dashboard.stripe.com/dashboard';
    }

    /**
     * Fields for a new account. The logo needs an existing account to upload to, so it follows with updateBranding().
     *
     * @return array<string, mixed>
     */
    private function defaults(?AccountDefaultsData $defaults): array
    {
        if ($defaults === null) {
            return [];
        }

        $merchant = array_filter([
            'statement_descriptor' => $defaults->statementDescriptor === null ? null : ['descriptor' => $defaults->statementDescriptor],
            'support' => array_filter([
                'email' => $defaults->supportEmail,
                'phone' => $defaults->supportPhone,
                'url' => $defaults->supportUrl,
            ]) ?: null,
            'branding' => array_filter([
                'primary_color' => $defaults->branding?->primaryColor,
                'secondary_color' => $defaults->branding?->secondaryColor,
            ]) ?: null,
        ]);

        return array_filter([
            'configuration' => $merchant === [] ? null : ['merchant' => $merchant],
            'defaults' => $defaults->locales === [] ? null : ['locales' => $defaults->locales],
        ]);
    }

    private function uploadLogo(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The logo could not be read.');
        }

        $file = tmpfile();
        fwrite($file, $contents);
        rewind($file);

        // Accounts v2 is called as the platform, so the file belongs to the platform, not the connected account.
        return $this->stripe->files->create(['purpose' => 'business_logo', 'file' => $file])->id;
    }

    private function toData(Account $account): ProviderAccountData
    {
        $capabilities = $account->configuration?->merchant?->capabilities;
        $paymentsStatus = $capabilities?->card_payments?->status ?? 'unrequested';
        $payoutsStatus = $capabilities?->stripe_balance?->payouts?->status ?? 'unrequested';

        $requirementsDue = collect($account->requirements?->entries ?? [])
            ->filter(fn ($entry): bool => $entry->awaiting_action_from === 'user'
                && in_array($entry->minimum_deadline->status, ['currently_due', 'past_due'], true))
            ->map(fn ($entry): string => $entry->description)
            ->values()
            ->all();

        return new ProviderAccountData(
            providerAccountId: $account->id,
            status: $this->status($account, $paymentsStatus, $requirementsDue),
            businessName: $account->identity?->business_details?->registered_name ?? $account->display_name,
            email: $account->contact_email,
            capabilities: ['payments' => $paymentsStatus, 'payouts' => $payoutsStatus],
            requirementsDue: $requirementsDue,
        );
    }

    /**
     * @param list<string> $requirementsDue
     */
    private function status(Account $account, string $paymentsStatus, array $requirementsDue): PaymentAccountStatus
    {
        return match (true) {
            (bool) $account->closed => PaymentAccountStatus::DEACTIVATED,
            $paymentsStatus === 'active' => PaymentAccountStatus::ACTIVE,
            $requirementsDue !== [] => PaymentAccountStatus::ONBOARDING,
            default => PaymentAccountStatus::RESTRICTED,
        };
    }
}
