<?php

namespace NetOS\Payments\Models\Concerns;

/**
 * Models that live with the application's bookings; null means the default connection.
 */
trait UsesTenantConnection
{
    public function getConnectionName(): ?string
    {
        return config('payments.connections.tenant');
    }
}
