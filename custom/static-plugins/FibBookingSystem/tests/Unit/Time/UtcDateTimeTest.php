<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Time;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use PHPUnit\Framework\TestCase;

/**
 * The UTC invariant: identical instants described with different offsets must
 * normalize to the SAME storage string — otherwise the availability math
 * counts the same physical window twice (oversell) and holds expire shifted.
 */
class UtcDateTimeTest extends TestCase
{
    public function testNowIsLabelledUtc(): void
    {
        static::assertSame('UTC', UtcDateTime::now()->getTimezone()->getName());
    }

    public function testFromConvertsOffsetToUtcInstant(): void
    {
        $berlin = new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('Europe/Berlin'));

        $utc = UtcDateTime::from($berlin);

        static::assertSame('UTC', $utc->getTimezone()->getName());
        static::assertSame('2026-07-01 08:00:00.000', $utc->format(UtcDateTime::STORAGE_FORMAT));
    }

    public function testFromAcceptsMutableDateTime(): void
    {
        $mutable = new DateTime('2026-07-01 10:00:00+02:00');

        static::assertSame('2026-07-01 08:00:00.000', UtcDateTime::toStorage($mutable));
    }

    public function testParseSameInstantDifferentOffsetsNormalizesIdentically(): void
    {
        $viaOffset = UtcDateTime::parse('2026-07-01T10:00:00+02:00');
        $viaZulu = UtcDateTime::parse('2026-07-01T08:00:00Z');

        static::assertSame(
            UtcDateTime::toStorage($viaOffset),
            UtcDateTime::toStorage($viaZulu),
            'same physical instant must produce the same storage value',
        );
    }

    public function testParseTreatsNaiveStringsAsUtcNotPhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $parsed = UtcDateTime::parse('2026-07-01 08:00:00.000');

            static::assertSame('UTC', $parsed->getTimezone()->getName());
            static::assertSame('2026-07-01 08:00:00.000', $parsed->format(UtcDateTime::STORAGE_FORMAT));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testNowIsUnaffectedByPhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14, maximum drift

        try {
            $drift = abs(UtcDateTime::now()->getTimestamp() - time());

            static::assertLessThan(5, $drift, 'now() must be the real instant, not a shifted wall time');
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testToStorageKeepsMillisecondPrecision(): void
    {
        $instant = new DateTimeImmutable('2026-07-01 08:00:00.123', new DateTimeZone('UTC'));

        static::assertSame('2026-07-01 08:00:00.123', UtcDateTime::toStorage($instant));
    }

    public function testParseRejectsGarbage(): void
    {
        $this->expectException(Exception::class);

        UtcDateTime::parse('not-a-date');
    }
}
