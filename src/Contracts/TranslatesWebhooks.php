<?php

namespace NetOS\Payments\Contracts;

use Illuminate\Http\Request;
use NetOS\Payments\Data\ProviderEventData;

interface TranslatesWebhooks
{
    /**
     * Verifies the request's signature and maps it to a provider-neutral event.
     */
    public function translate(Request $request): ProviderEventData;

    /**
     * Whether the event says something changed on a payment account.
     */
    public function isAccountEvent(string $type): bool;

    /**
     * The checkout the event is about, or null when it is not a checkout event.
     */
    public function checkoutId(ProviderEventData $event): ?string;
}
