<?php

namespace NetOS\Payments\Drivers\Concerns;

use Illuminate\Support\Collection;
use NetOS\Payments\Data\DiscountValidationData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\DiscountCodeStatus;
use NetOS\Payments\Models\PaymentDiscountCode;
use NetOS\Payments\PaymentContext;

/**
 * Both drivers keep their codes in the same table; a code only counts for the source it was created for.
 */
trait ValidatesDiscountCodes
{
    public function validate(PaymentContext $context, string $code, ?string $reference = null): DiscountValidationData
    {
        $discountCode = PaymentDiscountCode::query()
            ->whereCode($code)
            ->where('source', $context->sources->discounts)
            // A Stripe code only works on the account it was created on.
            ->when(
                $context->sources->discounts === CapabilitySource::STRIPE,
                fn ($query) => $query->where('provider_account_id', $context->providerAccountId),
            )
            ->first();

        if ($discountCode === null) {
            return DiscountValidationData::invalid('This code does not exist.');
        }

        $status = $discountCode->status;
        $isOwnHold = $status === DiscountCodeStatus::RESERVED && ! $discountCode->isReserved($reference);
        $reason = $isOwnHold ? null : $status->reason();

        return $reason === null
            ? new DiscountValidationData(true, null, $discountCode)
            : DiscountValidationData::invalid($reason, $discountCode);
    }

    /**
     * @return Collection<int, PaymentDiscountCode>
     */
    public function list(PaymentContext $context): Collection
    {
        return PaymentDiscountCode::query()
            ->where('source', $context->sources->discounts)
            ->when(
                $context->sources->discounts === CapabilitySource::STRIPE,
                fn ($query) => $query->where('provider_account_id', $context->providerAccountId),
            )
            ->latest()
            ->get();
    }
}
