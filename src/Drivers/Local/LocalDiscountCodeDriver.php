<?php

namespace NetOS\Payments\Drivers\Local;

use Carbon\CarbonInterface;
use NetOS\Payments\Contracts\ManagesDiscountCodes;
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Drivers\Concerns\ValidatesDiscountCodes;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\DiscountCodeType;
use NetOS\Payments\Models\PaymentDiscountCode;
use NetOS\Payments\PaymentContext;

/**
 * Codes that exist only in the application. The application lowers the line items before the checkout, so the
 * provider never sees the code.
 */
class LocalDiscountCodeDriver implements ManagesDiscountCodes
{
    use ValidatesDiscountCodes;

    public function isHandledByCheckout(): bool
    {
        return false;
    }

    public function create(PaymentContext $context, CreateDiscountCodeData $data): PaymentDiscountCode
    {
        return PaymentDiscountCode::query()->create([
            'source' => CapabilitySource::LOCAL,
            'name' => $data->name,
            'description' => $data->description,
            'code' => mb_strtoupper($data->code),
            'type' => $data->type,
            'percent_off' => $data->percentOff,
            'valid_from' => $data->validFrom,
            'valid_until' => $data->validUntil,
            'created_by' => $data->createdBy,
        ]);
    }

    public function setActive(
        PaymentContext $context,
        PaymentDiscountCode $discountCode,
        bool $isActive,
        ?int $actorId = null,
    ): PaymentDiscountCode {
        $discountCode->update([
            'deactivated_at' => $isActive ? null : now(),
            'deactivated_by' => $isActive ? null : $actorId,
        ]);

        return $discountCode;
    }

    public function reserve(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference, CarbonInterface $until): bool
    {
        if ($discountCode->type !== DiscountCodeType::ONE_TIME) {
            return true;
        }

        // One conditional update, so two checkouts racing for the same code cannot both win.
        $isReserved = PaymentDiscountCode::query()
            ->whereKey($discountCode->id)
            ->where('times_redeemed', 0)
            ->where(fn ($query) => $query
                ->whereNull('reserved_until')
                ->orWhere('reserved_until', '<', now())
                ->orWhere('reserved_reference', $reference))
            ->update(['reserved_reference' => $reference, 'reserved_until' => $until]) > 0;

        $discountCode->refresh();

        return $isReserved;
    }

    public function release(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference): void
    {
        PaymentDiscountCode::query()
            ->whereKey($discountCode->id)
            ->where('reserved_reference', $reference)
            ->update(['reserved_reference' => null, 'reserved_until' => null]);

        $discountCode->refresh();
    }

    public function redeem(PaymentContext $context, PaymentDiscountCode $discountCode): PaymentDiscountCode
    {
        $discountCode->increment('times_redeemed', 1, ['reserved_reference' => null, 'reserved_until' => null]);

        return $discountCode;
    }
}
