<?php

namespace NetOS\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\DiscountCodeStatus;
use NetOS\Payments\Enums\DiscountCodeType;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Models\Concerns\UsesTenantConnection;

/**
 * @property int $id
 * @property CapabilitySource $source
 * @property string $name
 * @property string|null $description
 * @property string $code
 * @property DiscountCodeType $type
 * @property int $percent_off
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property Provider|null $provider
 * @property string|null $provider_account_id
 * @property string|null $provider_discount_id
 * @property string|null $provider_coupon_id
 * @property int $times_redeemed
 * @property string|null $reserved_reference
 * @property CarbonImmutable|null $reserved_until
 * @property int|null $created_by
 * @property CarbonImmutable|null $deactivated_at
 * @property int|null $deactivated_by
 * @property CarbonImmutable $created_at
 * @property DiscountCodeStatus $status
 */
class PaymentDiscountCode extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'source',
        'name',
        'description',
        'code',
        'type',
        'percent_off',
        'valid_from',
        'valid_until',
        'provider',
        'provider_account_id',
        'provider_discount_id',
        'provider_coupon_id',
        'times_redeemed',
        'reserved_reference',
        'reserved_until',
        'created_by',
        'deactivated_at',
        'deactivated_by',
    ];

    /**
     * @param Builder<self> $query
     */
    public function scopeWhereCode(Builder $query, string $code): void
    {
        $query->where('code', mb_strtoupper(mb_trim($code)));
    }

    public function status(): Attribute
    {
        return Attribute::get(fn (): DiscountCodeStatus => match (true) {
            $this->deactivated_at !== null => DiscountCodeStatus::INACTIVE,
            $this->type === DiscountCodeType::ONE_TIME && $this->times_redeemed > 0 => DiscountCodeStatus::USED,
            $this->valid_until !== null && $this->valid_until->isBefore(today()) => DiscountCodeStatus::EXPIRED,
            $this->valid_from->isAfter(today()) => DiscountCodeStatus::SCHEDULED,
            $this->isReserved() => DiscountCodeStatus::RESERVED,
            default => DiscountCodeStatus::ACTIVE,
        });
    }

    /**
     * Held by an open checkout; pass that checkout's reference to treat its own hold as free.
     */
    public function isReserved(?string $exceptReference = null): bool
    {
        return $this->type === DiscountCodeType::ONE_TIME
            && $this->reserved_until !== null
            && $this->reserved_until->isFuture()
            && ($exceptReference === null || $this->reserved_reference !== $exceptReference);
    }

    protected function casts(): array
    {
        return [
            'source' => CapabilitySource::class,
            'type' => DiscountCodeType::class,
            'percent_off' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'provider' => Provider::class,
            'times_redeemed' => 'integer',
            'deactivated_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
