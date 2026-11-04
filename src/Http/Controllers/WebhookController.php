<?php

namespace NetOS\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Jobs\ProcessWebhookEventJob;
use NetOS\Payments\Models\PaymentWebhookEvent;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class WebhookController
{
    public function __invoke(Request $request, string $provider): Response
    {
        $provider = Provider::tryFrom($provider) ?? abort(404);

        try {
            $data = Payments::webhooks($provider)->translate($request);
        } catch (SignatureVerificationException | UnexpectedValueException) {
            abort(400);
        }

        $event = PaymentWebhookEvent::query()->firstOrCreate(
            ['provider' => $data->provider, 'provider_event_id' => $data->providerEventId],
            ['type' => $data->type, 'provider_account_id' => $data->providerAccountId, 'payload' => $data->payload],
        );

        // A duplicate delivery is acknowledged without being processed again.
        if ($event->wasRecentlyCreated) {
            ProcessWebhookEventJob::dispatch($event->id);
        }

        return response()->noContent();
    }
}
