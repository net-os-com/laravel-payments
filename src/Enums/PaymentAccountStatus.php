<?php

namespace NetOS\Payments\Enums;

enum PaymentAccountStatus: string
{
    // Created, but the account holder still has to provide information before it can take payments.
    case ONBOARDING = 'onboarding';

    // Can take payments.
    case ACTIVE = 'active';

    // Onboarded, but the provider has disabled or not yet enabled payments, e.g. while it verifies the details.
    case RESTRICTED = 'restricted';

    // No longer used by its owner; it still exists at the provider.
    case DEACTIVATED = 'deactivated';
}
