<?php

namespace NetOS\Payments\Drivers\Stripe;

use Carbon\CarbonInterface;
use NetOS\Payments\Contracts\ManagesDiscountCodes;
use NetOS\Payments\Data\CreateDiscountCodeData;
use NetOS\Payments\Drivers\Concerns\ValidatesDiscountCodes;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\DiscountCodeType;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Models\PaymentDiscountCode;
use NetOS\Payments\PaymentContext;
use Stripe\StripeClient;

/**
 * A coupon with a promotion code on the connected account, mirrored locally. The coupon holds the percentage; the
 * promotion code holds what the customer types, how often it can be used and until when.
 */
class StripeDiscountCodeDriver implements ManagesDiscountCodes
{
    use ValidatesDiscountCodes;

    private const int COUPON_NAME_MAX_LENGTH = 40;

    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function isHandledByCheckout(): bool
    {
        return true;
    }

    public function create(PaymentContext $context, CreateDiscountCodeData $data): PaymentDiscountCode
    {
        $accountId = $context->requireAccount();
        $options = ['stripe_account' => $accountId];
        $code = mb_strtoupper($data->code);
        $expiresAt = $data->validUntil?->endOfDay()->getTimestamp();

        $coupon = $this->stripe->coupons->create(array_filter([
            'name' => mb_substr($data->name, 0, self::COUPON_NAME_MAX_LENGTH),
            'percent_off' => $data->percentOff,
            'duration' => 'once',
            'redeem_by' => $expiresAt,
            'metadata' => $data->metadata,
        ], fn (mixed $value): bool => $value !== null), $options);

        $promotionCode = $this->stripe->promotionCodes->create(array_filter([
            'promotion' => ['type' => 'coupon', 'coupon' => $coupon->id],
            'code' => $code,
            'max_redemptions' => $data->type === DiscountCodeType::ONE_TIME ? 1 : null,
            'expires_at' => $expiresAt,
            'metadata' => $data->metadata,
        ], fn (mixed $value): bool => $value !== null), $options);

        return PaymentDiscountCode::query()->create([
            'source' => CapabilitySource::STRIPE,
            'name' => $data->name,
            'description' => $data->description,
            'code' => $code,
            'type' => $data->type,
            'percent_off' => $data->percentOff,
            'valid_from' => $data->validFrom,
            'valid_until' => $data->validUntil,
            'provider' => Provider::STRIPE,
            'provider_account_id' => $accountId,
            'provider_discount_id' => $promotionCode->id,
            'provider_coupon_id' => $coupon->id,
            'times_redeemed' => $promotionCode->times_redeemed,
            'created_by' => $data->createdBy,
        ]);
    }

    public function setActive(
        PaymentContext $context,
        PaymentDiscountCode $discountCode,
        bool $isActive,
        ?int $actorId = null,
    ): PaymentDiscountCode {
        $promotionCode = $this->stripe->promotionCodes->update(
            (string) $discountCode->provider_discount_id,
            ['active' => $isActive],
            ['stripe_account' => $discountCode->provider_account_id ?? $context->requireAccount()],
        );

        $discountCode->update([
            'times_redeemed' => $promotionCode->times_redeemed,
            'deactivated_at' => $isActive ? null : now(),
            'deactivated_by' => $isActive ? null : $actorId,
        ]);

        return $discountCode;
    }

    /**
     * Stripe counts the redemption itself; the mirror copies its count.
     */
    /**
     * Stripe enforces max_redemptions itself, so nothing is held.
     */
    public function reserve(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference, CarbonInterface $until): bool
    {
        return true;
    }

    public function release(PaymentContext $context, PaymentDiscountCode $discountCode, string $reference): void {}

    public function redeem(PaymentContext $context, PaymentDiscountCode $discountCode): PaymentDiscountCode
    {
        $promotionCode = $this->stripe->promotionCodes->retrieve(
            (string) $discountCode->provider_discount_id,
            [],
            ['stripe_account' => $discountCode->provider_account_id ?? $context->requireAccount()],
        );
        $discountCode->update(['times_redeemed' => $promotionCode->times_redeemed]);

        return $discountCode;
    }
}
