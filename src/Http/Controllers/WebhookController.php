<?php

namespace NetOS\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $keys = ['provider' => $data->provider, 'provider_event_id' => $data->providerEventId];

        try {
            $event = PaymentWebhookEvent::query()->firstOrCreate(
                $keys,
                ['type' => $data->type, 'provider_account_id' => $data->providerAccountId, 'payload' => $data->payload],
            );
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same event at once: the other request stored it.
            $event = PaymentWebhookEvent::query()->where($keys)->firstOrFail();
        }

        // A redelivery of an event that was never processed (e.g. the dispatch failed) is queued again; the job is
        // skips processed ones, so duplicates do no harm.
        if ($event->processed_at === null) {
            ProcessWebhookEventJob::dispatch($event->id);
        }

        return response()->noContent();
    }
}
