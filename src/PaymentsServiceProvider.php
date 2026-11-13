<?php

namespace NetOS\Payments;

use Illuminate\Support\ServiceProvider;
use NetOS\Payments\Console\RetryWebhookEventsCommand;
use NetOS\Payments\Managers\AccountManager;
use NetOS\Payments\Managers\CheckoutManager;
use NetOS\Payments\Managers\DiscountCodeManager;
use NetOS\Payments\Managers\InvoiceManager;
use NetOS\Payments\Managers\RefundManager;
use NetOS\Payments\Managers\TaxManager;
use NetOS\Payments\Managers\WebhookManager;
use Stripe\StripeClient;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payments.php', 'payments');

        // Singletons, so a driver registered with extend() on a manager is the one Payments uses.
        foreach ([
            AccountManager::class,
            CheckoutManager::class,
            RefundManager::class,
            InvoiceManager::class,
            TaxManager::class,
            DiscountCodeManager::class,
            WebhookManager::class,
        ] as $manager) {
            $this->app->singleton($manager);
        }

        $this->app->singleton(Payments::class);
        $this->app->singleton(
            StripeClient::class,
            fn (): StripeClient => new StripeClient(['api_key' => config('payments.stripe.secret') ?: null]),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/documents.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payments');

        $this->publishes([__DIR__ . '/../config/payments.php' => config_path('payments.php')], 'payments-config');
        $this->publishes([__DIR__ . '/../database/migrations' => database_path('migrations')], 'payments-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RetryWebhookEventsCommand::class]);
        }
        $this->publishes([__DIR__ . '/../resources/views' => resource_path('views/vendor/payments')], 'payments-views');
    }
}
