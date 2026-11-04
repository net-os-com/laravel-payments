<?php

namespace NetOS\Payments;

use NetOS\Payments\Data\CapabilitySourcesData;
use NetOS\Payments\Data\IssuerData;
use NetOS\Payments\Enums\PaymentProfile;
use NetOS\Payments\Enums\Provider;

/**
 * Everything a driver needs to know about whose money it handles. The application builds it (ResolvesPaymentContext)
 * and checks the sources there with CapabilitySourcesData::assertAllowed().
 *
 * Passed into every call, so drivers keep no state
 * and stay safe in long-running workers.
 */
final readonly class PaymentContext
{
    public function __construct(
        public Provider $provider,
        /** The merchant's account at the provider; null before it is connected. */
        public ?string $providerAccountId,
        public CapabilitySourcesData $sources,
        public ?IssuerData $issuer = null,
    ) {}

    /**
     * For calls that only need the account, such as syncing it from a webhook.
     */
    public static function forAccount(Provider $provider, ?string $providerAccountId): self
    {
        return new self($provider, $providerAccountId, PaymentProfile::STRIPE_MANAGED->sources());
    }

    public function withAccount(?string $providerAccountId): self
    {
        return new self($this->provider, $providerAccountId, $this->sources, $this->issuer);
    }

    /**
     * The same account with other sources, e.g. to manage a discount code created under a source no longer in use.
     */
    public function withSources(CapabilitySourcesData $sources): self
    {
        return new self($this->provider, $this->providerAccountId, $sources, $this->issuer);
    }

    public function withDiscountSource(Enums\CapabilitySource $source): self
    {
        return $this->withSources(new CapabilitySourcesData($this->sources->invoicing, $this->sources->tax, $source));
    }

    public function withInvoicingSource(Enums\CapabilitySource $source): self
    {
        return $this->withSources(new CapabilitySourcesData($source, $this->sources->tax, $this->sources->discounts));
    }

    public function requireAccount(): string
    {
        return $this->providerAccountId ?? throw new Exceptions\AccountNotConnectedException();
    }
}
