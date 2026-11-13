<?php

namespace NetOS\Payments\Console;

use Illuminate\Console\Command;
use NetOS\Payments\Jobs\ProcessWebhookEventJob;
use NetOS\Payments\Models\PaymentWebhookEvent;

/**
 * Queues stored webhook events that were never processed, e.g. because the queue was down when they arrived or every
 * attempt failed. Safe to run on a schedule: the job skips events that are already processed.
 */
class RetryWebhookEventsCommand extends Command
{
    protected $signature = 'payments:retry-webhooks {--older-than=10 : Only events stored at least this many minutes ago}';

    protected $description = 'Queue unprocessed payment webhook events again';

    public function handle(): int
    {
        $count = 0;

        PaymentWebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->orderBy('id')
            ->each(function (PaymentWebhookEvent $event) use (&$count): void {
                ProcessWebhookEventJob::dispatch($event->id);
                $count++;
            });

        $this->info(sprintf('Queued %d webhook %s.', $count, $count === 1 ? 'event' : 'events'));

        return self::SUCCESS;
    }
}
