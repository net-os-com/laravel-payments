<?php

namespace NetOS\Payments\Drivers\Stripe;

use Stripe\StripeClient;

/**
 * Inclusive tax rates on a connected account, for tax that the application calculated. Reused by percentage; found
 * rates are remembered per account for the lifetime of this object, so a checkout with many lines asks Stripe once.
 */
class StripeTaxRates
{
    /** @var array<string, string> tax rate id per account and percentage */
    private array $found = [];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $country,
    ) {}

    public function inclusive(string $providerAccountId, float $percentage): string
    {
        $key = sprintf('%s:%s', $providerAccountId, number_format($percentage, 4, '.', ''));

        return $this->found[$key] ??= $this->findOrCreate($providerAccountId, $percentage);
    }

    private function findOrCreate(string $providerAccountId, float $percentage): string
    {
        $options = ['stripe_account' => $providerAccountId];
        $country = mb_strtoupper($this->country);
        $rates = $this->stripe->taxRates->all(['active' => true, 'inclusive' => true, 'limit' => 100], $options);

        // All pages, and compared with a tolerance: Stripe returns percentages as floats.
        foreach ($rates->autoPagingIterator() as $taxRate) {
            if (abs((float) $taxRate->percentage - $percentage) < 0.0001 && $taxRate->country === $country) {
                return $taxRate->id;
            }
        }

        return $this->stripe->taxRates->create([
            'display_name' => 'VAT',
            'percentage' => $percentage,
            'inclusive' => true,
            'country' => $country,
            'jurisdiction' => $country,
        ], $options)->id;
    }
}
