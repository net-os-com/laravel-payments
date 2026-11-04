<?php

namespace NetOS\Payments\Drivers\Concerns;

use Illuminate\Support\Collection;
use NetOS\Payments\Data\DiscountValidationData;
use NetOS\Payments\Models\PaymentDiscountCode;
use NetOS\Payments\PaymentContext;

/**
 * Both drivers keep their codes in the same table; a code only counts for the source it was created for.
 */
trait ValidatesDiscountCodes
{
    public function validate(PaymentContext $context, string $code): DiscountValidationData
    {
        $discountCode = PaymentDiscountCode::query()
            ->whereCode($code)
            ->where('source', $context->sources->discounts)
            ->first();

        if ($discountCode === null) {
            return DiscountValidationData::invalid('This code does not exist.');
        }

        $reason = $discountCode->status->reason();

        return $reason === null
            ? new DiscountValidationData(true, null, $discountCode)
            : DiscountValidationData::invalid($reason, $discountCode);
    }

    /**
     * @return Collection<int, PaymentDiscountCode>
     */
    public function list(PaymentContext $context): Collection
    {
        return PaymentDiscountCode::query()->latest()->get();
    }
}
