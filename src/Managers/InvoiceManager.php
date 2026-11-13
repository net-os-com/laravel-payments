<?php

namespace NetOS\Payments\Managers;

use Illuminate\Support\Manager;
use NetOS\Payments\Contracts\ProvidesInvoices;
use NetOS\Payments\Drivers\Local\LocalInvoiceDriver;
use NetOS\Payments\Drivers\Stripe\StripeInvoiceDriver;
use NetOS\Payments\Support\DocumentNumberer;
use NetOS\Payments\Support\LocalDocumentPresenter;
use Stripe\StripeClient;

/**
 * @method ProvidesInvoices driver(?string $driver = null)
 */
class InvoiceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'stripe';
    }

    public function createStripeDriver(): ProvidesInvoices
    {
        return new StripeInvoiceDriver($this->container->make(StripeClient::class));
    }

    public function createLocalDriver(): ProvidesInvoices
    {
        return new LocalInvoiceDriver(
            $this->container->make(DocumentNumberer::class),
            $this->container->make(LocalDocumentPresenter::class),
            $this->config->get('payments.local.invoice_number_prefix'),
            $this->config->get('payments.local.credit_note_number_prefix'),
        );
    }
}
