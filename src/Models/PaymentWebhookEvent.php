<?php

namespace NetOS\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NetOS\Payments\Enums\Provider;

/**
 * @property int $id
 * @property Provider $provider
 * @property string $provider_event_id
 * @property ?string $provider_account_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property ?string $exception
 * @property ?Carbon $processed_at
 * @property ?Carbon $failed_at
 */
class PaymentWebhookEvent extends Model
{
    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return config('payments.connections.central') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
