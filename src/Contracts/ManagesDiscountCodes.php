<?php

namespace NetOS\Payments\Contracts;

use Carbon\CarbonInterface;
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

    /**
     * Checks whether the customer can use the code now. A hold by the checkout with this reference does not count.
     */
    public function validate(PaymentContext $context, string $code, ?string $reference = null): DiscountValidationData;

    /**
     * Holds a one-time code for one open checkout until it ends; false when another checkout holds it. Codes the
     * provider enforces itself are not held.
     */
    public function reserve(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference, CarbonInterface $until): bool;

    /**
     * Frees the hold of this checkout, e.g. when it expired or was cancelled.
     */
    public function release(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference): void;

    /**
     * Records that a paid checkout used the code, and ends any hold on it.
     */
    public function redeem(PaymentContext $context, PaymentDiscountCode $discountCode): PaymentDiscountCode;

    /**
     * @return Collection<int, PaymentDiscountCode>
     */
    public function list(PaymentContext $context): Collection;
}
