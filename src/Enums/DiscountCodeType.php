<?php

namespace NetOS\Payments\Enums;

enum DiscountCodeType: string
{
    /**
     * Works once, then expires.
     */
    case ONE_TIME = 'one_time';

    /**
     * Anyone can use it between its start and end date.
     */
    case LONG_TERM = 'long_term';
}
