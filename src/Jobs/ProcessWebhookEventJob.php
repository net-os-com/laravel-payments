<?php

namespace NetOS\Payments\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NetOS\Payments\Actions\SyncPaymentAccountAction;
use NetOS\Payments\Data\ProviderEventData;
use NetOS\Payments\Events\PaymentCheckoutUpdated;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentAccount;
use NetOS\Payments\Models\PaymentWebhookEvent;
use Throwable;

class ProcessWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue(config('payments.queue'));
    }

    public function handle(): void
    {
        $event = PaymentWebhookEvent::query()->findOrFail($this->webhookEventId);

        if ($event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');

        try {
            $this->process($event);
            $event->update(['processed_at' => now(), 'failed_at' => null, 'exception' => null]);
        } catch (Throwable $exception) {
            $event->update(['failed_at' => now(), 'exception' => (string) $exception]);

            throw $exception;
        }
    }

    private function process(PaymentWebhookEvent $event): void
    {
        if ($event->provider_account_id === null) {
            return;
        }

        $checkoutId = Payments::webhooks($event->provider)->checkoutId(new ProviderEventData(
            provider: $event->provider,
            providerEventId: $event->provider_event_id,
            type: $event->type,
            providerAccountId: $event->provider_account_id,
            payload: $event->payload,
        ));

        if ($checkoutId !== null) {
            PaymentCheckoutUpdated::dispatch($event->provider, $event->provider_account_id, $checkoutId);

            return;
        }

        // Unknown types are stored and skipped.
        if (! Payments::webhooks($event->provider)->isAccountEvent($event->type)) {
            return;
        }

        $account = PaymentAccount::query()
            ->where('provider', $event->provider)
            ->where('provider_account_id', $event->provider_account_id)
            ->first();

        if ($account !== null) {
            SyncPaymentAccountAction::make()->handle($account);
        }
    }
}
