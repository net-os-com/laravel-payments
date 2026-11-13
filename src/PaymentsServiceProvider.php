<?php

namespace NetOS\Payments;

use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payments.php', 'payments');

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
        $this->publishes([__DIR__ . '/../resources/views' => resource_path('views/vendor/payments')], 'payments-views');
    }
}
