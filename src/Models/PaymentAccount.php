<?php

namespace NetOS\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use NetOS\Payments\Enums\PaymentAccountStatus;
use NetOS\Payments\Enums\Provider;

/**
 * An account at a payment provider. An owner can have several over time; the one without deactivated_at is current.
 *
 * @property int $id
 * @property string $owner_type
 * @property string $owner_id
 * @property Provider $provider
 * @property string $provider_account_id
 * @property PaymentAccountStatus $status
 * @property ?string $business_name
 * @property ?string $email
 * @property ?array<string, string> $capabilities
 * @property ?list<string> $requirements_due
 * @property ?string $connected_by
 * @property ?Carbon $synced_at
 * @property ?Carbon $activated_at
 * @property ?Carbon $deactivated_at
 * @property ?Carbon $created_at
 */
class PaymentAccount extends Model
{
    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return config('payments.connections.central') ?? parent::getConnectionName();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param Builder<$this> $query
     */
    public function scopeForOwner(Builder $query, Model $owner): void
    {
        $query->where('owner_type', $owner->getMorphClass())->where('owner_id', (string) $owner->getKey());
    }

    /**
     * @param Builder<$this> $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => PaymentAccountStatus::class,
            'capabilities' => 'array',
            'requirements_due' => 'array',
            'synced_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }
}
