<?php

namespace NetOS\Payments\Drivers\Stripe;

use Stripe\StripeClient;

/**
 * Inclusive tax rates on a connected account, for tax that the application calculated. Reused by percentage.
 */
class StripeTaxRates
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $country,
    ) {}

    public function inclusive(string $providerAccountId, float $percentage): string
    {
        $options = ['stripe_account' => $providerAccountId];
        $existing = $this->stripe->taxRates->all(['active' => true, 'inclusive' => true, 'limit' => 100], $options);

        foreach ($existing->data as $taxRate) {
            if ((float) $taxRate->percentage === $percentage && $taxRate->country === mb_strtoupper($this->country)) {
                return $taxRate->id;
            }
        }

        return $this->stripe->taxRates->create([
            'display_name' => 'VAT',
            'percentage' => $percentage,
            'inclusive' => true,
            'country' => mb_strtoupper($this->country),
            'jurisdiction' => mb_strtoupper($this->country),
        ], $options)->id;
    }
}
