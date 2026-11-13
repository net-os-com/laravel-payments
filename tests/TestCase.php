<?php

namespace NetOS\Payments\Tests;

use NetOS\Payments\Data\IssuerData;
use NetOS\Payments\Enums\CapabilitySource;
use NetOS\Payments\Enums\PaymentProfile;
use NetOS\Payments\Enums\Provider;
use NetOS\Payments\PaymentContext;
use NetOS\Payments\PaymentsServiceProvider;
use NetOS\Payments\Tests\Support\FakeStripe;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelPdf\PdfServiceProvider;
use Stripe\ApiRequestor;

abstract class TestCase extends Orchestra
{
    protected FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripe();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            PdfServiceProvider::class,
            PaymentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('payments.stripe.secret', 'sk_test_package');
        $app['config']->set('payments.stripe.webhook_secrets', ['snapshot' => 'whsec_snapshot', 'thin' => 'whsec_thin']);
        $app['config']->set('payments.local.invoice_disk', 'local');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => sys_get_temp_dir() . '/payments-tests']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/tenant');
    }

    protected function context(
        PaymentProfile $profile = PaymentProfile::LOCALLY_MANAGED,
        ?string $accountId = 'acct_test',
    ): PaymentContext {
        return new PaymentContext(
            provider: Provider::STRIPE,
            providerAccountId: $accountId,
            sources: $profile->sources(),
            issuer: new IssuerData('Operator B.V.', "Street 1\n1000 AA Amsterdam\nNL", '12345678', 'NL123456789B01'),
            urlParameters: ['tenant' => 'acme'],
        );
    }

    protected function localContext(): PaymentContext
    {
        return $this->context()->withSources(PaymentProfile::LOCALLY_MANAGED->sources());
    }

    protected function stripeContext(): PaymentContext
    {
        return $this->context(PaymentProfile::STRIPE_MANAGED);
    }

    /**
     * Stripe-managed checkout, but discounts kept by the application.
     */
    protected function withLocalDiscounts(PaymentContext $context): PaymentContext
    {
        return $context->withDiscountSource(CapabilitySource::LOCAL);
    }
}
