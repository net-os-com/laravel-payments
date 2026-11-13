<?php

namespace NetOS\Payments\Enums;

enum DiscountCodeStatus: string
{
    case SCHEDULED = 'scheduled';
    case ACTIVE = 'active';
    case USED = 'used';
    /** A one-time code held by another open checkout. */
    case RESERVED = 'reserved';
    case EXPIRED = 'expired';
    case INACTIVE = 'inactive';

    /**
     * Why a customer cannot use a code in this state, or null when they can.
     */
    public function reason(): ?string
    {
        return match ($this) {
            self::ACTIVE => null,
            self::SCHEDULED => 'This code is not valid yet.',
            self::USED => 'This code has already been used.',
            self::RESERVED => 'This code is already in use.',
            self::EXPIRED, self::INACTIVE => 'This code is no longer valid.',
        };
    }
}
