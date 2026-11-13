# Tax

[Home](Home.md) › Tax

## Contract

```php
interface CalculatesTax
{
    /** True when the provider calculates tax inside its checkout; the app then cannot know it beforehand. */
    public function isHandledByCheckout(): bool;

    /**
     * @param array<TaxableLineData> $lines amounts include tax
     * @return array<TaxedLineData>
     */
    public function calculate(PaymentContext $context, array $lines): array;
}
```

Picked by `$context->sources->tax`.

| Driver | `isHandledByCheckout()` | `calculate()` |
| --- | --- | --- |
| `StripeTaxDriver` | `true` | throws `HandledByCheckoutException('Tax')` |
| `LocalTaxDriver` | `false` | inclusive VAT per line from `payment_tax_rates` |

All amounts are **tax-inclusive** minor units. The package never adds tax on top.

## Stripe Tax

With `tax = stripe`, `StripeCheckoutDriver` sets `automatic_tax.enabled = true`. Stripe calculates tax during Checkout from the customer's details. Requirements and consequences:

- The connected account needs its tax registrations set up in Stripe (the account holder does this in their dashboard).
- The app does not know the tax amount before payment. After payment it is in `ProviderCheckoutData::$amountTax`.
- Local invoicing is not allowed with Stripe tax (see [Payment Context](Payment-Context.md#validation-rule)).

## Local tax

### Data

```php
class TaxableLineData extends Data
{
    public function __construct(
        public string $description,
        public int $amount,       // minor units, incl. tax
        public string $category,  // what is sold, e.g. workspace_rental; picks the rate
    ) {}
}

class TaxedLineData extends Data
{
    public function __construct(
        public string $description,
        public int $amount,          // minor units, incl. tax
        public float $taxPercentage,
        public int $taxAmount,
    ) {}
}
```

### Calculation

`LocalTaxDriver` is built with `payments.local.tax_country`. For each line:

1. Looks up the rate for the line's category (cached per category for the duration of the call):

   ```php
   PaymentTaxRate::query()
       ->where('country', mb_strtoupper($country))
       ->where('category', $category)
       ->validOn(now())
       ->latest('valid_from')
       ->value('rate');
   ```

   `validOn($date)` keeps rows where `valid_from` is null or on/before the date and `valid_until` is null or on/after the date. When several rows match, the one with the latest `valid_from` wins.

2. Throws `RuntimeException('No tax rate for {category} in {COUNTRY}.')` when there is none.

3. Computes inclusive tax, rounded per line:

   ```
   taxAmount = amount - round(amount * 100 / (100 + rate))
   ```

   Example: 2400 cents at 21% → net `round(2400 * 100 / 121) = 1983`, tax `417`.

Notes:

- The rate is chosen for **today** (`now()`), not for the date of the supply.
- Only one country per app (`payments.local.tax_country`); there is no per-merchant or per-customer country.
- Rounding per line means the sum of line taxes can differ by a cent from tax on the total.

### The rate table

`payment_tax_rates` lives on the tenant connection. A rate change is a new row, not a code change.

| Column | Example | Notes |
| --- | --- | --- |
| `country` | `NL` | 2 characters. Store upper case: the driver queries the upper-cased config value. |
| `category` | `workspace_rental` | Free text, chosen by the app. |
| `rate` | `21.00` | `decimal(5,2)`, percentage. |
| `valid_from` | `null` or `2027-01-01` | Inclusive. |
| `valid_until` | `null` or `2026-12-31` | Inclusive. |

The package does not seed rates. Seed them in the app, for example in a tenant migration or seeder:

```php
PaymentTaxRate::query()->create([
    'country' => 'NL',
    'category' => 'workspace_rental',
    'rate' => 21,
    'valid_from' => null,
    'valid_until' => null,
]);
```

The reference host app inserts that one row per tenant in a migration.

### Passing local tax to Stripe

With local tax the app passes each line's `taxPercentage` in `CheckoutLineItemData`. The checkout driver turns it into an inclusive Stripe tax rate so receipts (and Stripe invoices, when invoicing is `stripe`) show the VAT.

## Inclusive tax rates on Stripe

`NetOS\Payments\Drivers\Stripe\StripeTaxRates::inclusive(string $providerAccountId, float $percentage): string`

1. Returns the id remembered for this account and percentage (rounded to 4 decimals), if any.
2. Otherwise lists the connected account's active inclusive tax rates (`taxRates.all(['active' => true, 'inclusive' => true, 'limit' => 100])`) and pages through all of them.
3. Returns the first whose `percentage` is within `0.0001` of the requested percentage (Stripe returns percentages as floats) and whose `country` equals the upper-cased `payments.stripe.country`.
4. Otherwise creates one: `display_name: VAT`, `percentage`, `inclusive: true`, `country` and `jurisdiction` set to the upper-cased country.

The found or created id is remembered per account and percentage for the lifetime of the `StripeTaxRates` object, so a checkout with many lines at the same rate asks Stripe once. The object belongs to the checkout driver, which the `CheckoutManager` singleton keeps, so in a long-running process (queue worker, Octane) the ids stay remembered until the process restarts.

Limitations:

- A rate archived at Stripe after it was remembered is still used until the process restarts.
- `display_name` is always `VAT`.

## Discounts and tax

Tax is calculated on the amount after discount. With local discounts the app lowers the line amount first and then calls `calculate()`; with Stripe discounts and local tax, Stripe applies the promotion code to the tax-inclusive price with an inclusive rate, so it reduces both net and tax.
