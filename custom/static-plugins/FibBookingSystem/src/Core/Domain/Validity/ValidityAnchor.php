<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Validity;

/**
 * WHERE a period ticket's validity window starts.
 */
final class ValidityAnchor
{
    /** Valid from the moment of purchase (classic monthly pass). */
    public const PURCHASE = 'purchase';

    /** Activated by the FIRST successful check-in (transit-style validation). */
    public const FIRST_USE = 'first_use';

    /** The customer picks the start date in the shop. */
    public const CUSTOMER = 'customer';

    public const ALL = [self::PURCHASE, self::FIRST_USE, self::CUSTOMER];

    private function __construct()
    {
    }
}
