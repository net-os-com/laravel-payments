<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use NetOS\Payments\Events\PaymentCheckoutUpdated;
use NetOS\Payments\Jobs\ProcessWebhookEventJob;
use NetOS\Payments\Models\PaymentWebhookEvent;

function signedStripeHeader(string $payload, string $secret): string
{
    $timestamp = time();

    return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $payload, $secret));
}

function checkoutEvent(string $id = 'evt_1'): string
{
    return json_encode([
        'id' => $id,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'account' => 'acct_test',
        'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session']],
    ], JSON_THROW_ON_ERROR);
}

function postWebhook(object $test, string $payload, ?string $signature = null): Illuminate\Testing\TestResponse
{
    return $test->call('POST', '/payments/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature ?? signedStripeHeader($payload, 'whsec_snapshot'),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload);
}

it('rejects a body that is not JSON with 400', function (): void {
    postWebhook($this, 'not json', 't=1,v1=x')->assertStatus(400);
});

it('rejects an invalid signature with 400', function (): void {
    postWebhook($this, checkoutEvent(), 't=1,v1=bad')->assertStatus(400);
    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('returns 404 for an unknown provider', function (): void {
    $this->postJson('/payments/webhooks/other', [])->assertNotFound();
});

it('stores a signed event once and queues it', function (): void {
    Queue::fake();

    postWebhook($this, checkoutEvent())->assertNoContent();

    $this->assertDatabaseHas('payment_webhook_events', ['provider_event_id' => 'evt_1', 'provider_account_id' => 'acct_test']);
    Queue::assertPushed(ProcessWebhookEventJob::class, 1);
});

it('queues a redelivery of an unprocessed event again but not of a processed one', function (): void {
    Queue::fake();
    postWebhook($this, checkoutEvent())->assertNoContent();
    postWebhook($this, checkoutEvent())->assertNoContent();

    Queue::assertPushed(ProcessWebhookEventJob::class, 2);
    expect(PaymentWebhookEvent::query()->count())->toBe(1);

    PaymentWebhookEvent::query()->update(['processed_at' => now()]);
    postWebhook($this, checkoutEvent())->assertNoContent();
    Queue::assertPushed(ProcessWebhookEventJob::class, 2);
});

it('turns a checkout event into PaymentCheckoutUpdated and marks it processed', function (): void {
    Event::fake([PaymentCheckoutUpdated::class]);
    Queue::fake();
    postWebhook($this, checkoutEvent());
    $event = PaymentWebhookEvent::query()->sole();

    (new ProcessWebhookEventJob($event->id))->handle();

    Event::assertDispatched(PaymentCheckoutUpdated::class, fn (PaymentCheckoutUpdated $updated): bool => $updated->providerCheckoutId === 'cs_test_1'
        && $updated->providerAccountId === 'acct_test');
    expect($event->refresh()->processed_at)->not->toBeNull()
        ->and($event->attempts)->toBe(1);

    (new ProcessWebhookEventJob($event->id))->handle();
    Event::assertDispatchedTimes(PaymentCheckoutUpdated::class, 1);
});

it('queues stored unprocessed events again with the retry command', function (): void {
    Queue::fake();
    postWebhook($this, checkoutEvent('evt_old'));
    postWebhook($this, checkoutEvent('evt_done'));
    PaymentWebhookEvent::query()->update(['created_at' => now()->subHour()]);
    PaymentWebhookEvent::query()->where('provider_event_id', 'evt_done')->update(['processed_at' => now()]);
    Queue::fake();

    $this->artisan('payments:retry-webhooks')->expectsOutput('Queued 1 webhook event.')->assertSuccessful();

    Queue::assertPushed(ProcessWebhookEventJob::class, 1);
});
