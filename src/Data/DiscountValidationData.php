<?php

namespace NetOS\Payments\Data;

use NetOS\Payments\Models\PaymentDiscountCode;
use Spatie\LaravelData\Data;

class DiscountValidationData extends Data
{
    public function __construct(
        public bool $isValid,
        public ?string $reason,
        public ?PaymentDiscountCode $discountCode,
    ) {}

    public static function invalid(string $reason, ?PaymentDiscountCode $discountCode = null): self
    {
        return new self(false, $reason, $discountCode);
    }
}
