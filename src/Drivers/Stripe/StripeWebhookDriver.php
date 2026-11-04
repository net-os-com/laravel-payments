<?php

namespace NetOS\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
use NetOS\Payments\Contracts\TranslatesWebhooks;
use NetOS\Payments\Data\ProviderEventData;
use NetOS\Payments\Enums\Provider;
use Stripe\Webhook;
use Stripe\WebhookSignature;

/**
 * Accepts both payload styles on one route: snapshot events of connected accounts (v1) and thin events (v2), each
 * signed with the secret of its own event destination.
 */
class StripeWebhookDriver implements TranslatesWebhooks
{
    public function __construct(
        private readonly ?string $snapshotSecret,
        private readonly ?string $thinSecret,
    ) {}

    public function translate(Request $request): ProviderEventData
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (($decoded['object'] ?? null) === 'v2.core.event') {
            WebhookSignature::verifyHeader($payload, $signature, (string) $this->thinSecret, Webhook::DEFAULT_TOLERANCE);

            $relatedObject = $decoded['related_object'] ?? null;

            return new ProviderEventData(
                provider: Provider::STRIPE,
                providerEventId: $decoded['id'],
                type: $decoded['type'],
                providerAccountId: ($relatedObject['type'] ?? null) === 'v2.core.account'
                    ? $relatedObject['id']
                    : ($decoded['context'] ?? null),
                payload: $decoded,
            );
        }

        $event = Webhook::constructEvent($payload, $signature, (string) $this->snapshotSecret);

        return new ProviderEventData(
            provider: Provider::STRIPE,
            providerEventId: $event->id,
            type: $event->type,
            providerAccountId: $event->account ?? null,
            payload: $decoded,
        );
    }

    public function checkoutId(ProviderEventData $event): ?string
    {
        if (! str_starts_with($event->type, 'checkout.session.')) {
            return null;
        }

        $id = $event->payload['data']['object']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function isAccountEvent(string $type): bool
    {
        return str_starts_with($type, 'v2.core.account');
    }
}
