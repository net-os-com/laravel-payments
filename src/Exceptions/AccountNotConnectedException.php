<?php

namespace NetOS\Payments\Exceptions;

use RuntimeException;

class AccountNotConnectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No payment account is connected.');
    }
}
