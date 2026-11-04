<?php

namespace NetOS\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NetOS\Payments\Models\PaymentAccount;

class PaymentAccountUpdated
{
    use Dispatchable;

    public function __construct(public readonly PaymentAccount $account) {}
}
