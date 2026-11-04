<?php

namespace NetOS\Payments\Contracts;

use Illuminate\Support\Collection;
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Data\DiscountValidationData;
use NetOS\Payments\Models\PaymentDiscountCode;
use NetOS\Payments\PaymentContext;

interface ManagesDiscountCodes
{
    /**
     * True when the provider applies the code inside its checkout; otherwise the application lowers the line items.
     */
    public function isHandledByCheckout(): bool;

    public function create(PaymentContext $context, CreateDiscountCodeData $data): PaymentDiscountCode;

    /**
     * Turns the code on or off; the discount and its usage history are kept.
     */
    public function setActive(
        PaymentContext $context,
        PaymentDiscountCode $discountCode,
        bool $isActive,
        ?int $actorId = null,
    ): PaymentDiscountCode;

    public function validate(PaymentContext $context, string $code): DiscountValidationData;

    /**
     * Records that a paid checkout used the code.
     */
    public function redeem(PaymentContext $context, PaymentDiscountCode $discountCode): PaymentDiscountCode;

    /**
     * @return Collection<int, PaymentDiscountCode>
     */
    public function list(PaymentContext $context): Collection;
}
