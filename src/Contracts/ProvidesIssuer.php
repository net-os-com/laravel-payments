<?php

namespace NetOS\Payments\Contracts;

use NetOS\Payments\Data\IssuerData;

/**
 * Implemented by the application: the merchant's legal details on local invoices.
 */
interface ProvidesIssuer
{
    public function issuer(): IssuerData;
}
