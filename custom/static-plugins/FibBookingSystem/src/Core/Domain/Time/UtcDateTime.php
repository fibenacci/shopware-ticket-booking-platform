<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Single source of truth for time handling in the booking domain.
 *
 * Every persisted timestamp is UTC wall time in a DATETIME(3) column and every
 * raw SQL comparison runs against UTC_TIMESTAMP(3). Therefore every DateTime
 * MUST be normalized to UTC before it is formatted, and every storage string
 * MUST be re-labelled as UTC when parsed. Client-supplied offsets (e.g.
 * `+02:00` from the storefront) or a non-UTC PHP default timezone would
 * otherwise shift booking windows by the offset — the same physical window
 * would be counted twice in the availability math (oversell) and holds would
 * expire hours early or late.
 */
final class UtcDateTime
{
    /** Matches DATETIME(3): millisecond precision, no offset suffix. */
    public const STORAGE_FORMAT = 'Y-m-d H:i:s.v';

    private static ?DateTimeZone $utc = null;

    private function __construct()
    {
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    /** Converts any (possibly offset-carrying) DateTime to its UTC instant. */
    public static function from(DateTimeInterface $dateTime): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dateTime)->setTimezone(self::timezone());
    }

    /**
     * Parses a datetime string into a UTC instant. An explicit offset in the
     * string wins (PHP ignores the timezone argument then); strings without an
     * offset — ISO client input as well as DATETIME(3) storage values — are
     * interpreted as UTC, never as the PHP default timezone.
     *
     * @throws Exception when the string is not a parsable datetime
     */
    public static function parse(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, self::timezone()))->setTimezone(self::timezone());
    }

    /** Formats for DATETIME(3) columns and raw SQL comparison parameters. */
    public static function toStorage(DateTimeInterface $dateTime): string
    {
        return self::from($dateTime)->format(self::STORAGE_FORMAT);
    }

    private static function timezone(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }
}
