<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Ticket;

use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
use PHPUnit\Framework\TestCase;

/**
 * The TOTP core: deterministic per window, accepts ±1 window drift, rejects
 * everything else, and round-trips through the wire format.
 */
class RotatingCodeServiceTest extends TestCase
{
    private const SECRET = 'b8f1c0de00000000000000000000000000000000000000000000000000000000';

    private RotatingCodeService $service;

    protected function setUp(): void
    {
        $this->service = new RotatingCodeService();
    }

    public function testCodeIsStableWithinAWindowAndChangesAcrossWindows(): void
    {
        $base = 1_700_000_000; // arbitrary fixed instant
        $a = $this->service->wireToken('T1', self::SECRET, 30, $base);
        $aSameWindow = $this->service->wireToken('T1', self::SECRET, 30, $base + 5);
        $bNextWindow = $this->service->wireToken('T1', self::SECRET, 30, $base + 30);

        static::assertSame($a, $aSameWindow, 'same 30s window → same code');
        static::assertNotSame($a, $bNextWindow, 'next window → different code');
    }

    public function testVerifyAcceptsCurrentAndAdjacentWindows(): void
    {
        $now = 1_700_000_000;
        [, $code] = $this->parse($this->service->wireToken('T1', self::SECRET, 30, $now));

        // exact, one window early, one window late — all accepted (drift)
        static::assertNotNull($this->service->verify($code, self::SECRET, 30, $now));
        static::assertNotNull($this->service->verify($code, self::SECRET, 30, $now + 30));
        static::assertNotNull($this->service->verify($code, self::SECRET, 30, $now - 30));
        $w = $this->service->verify($code, self::SECRET, 30, $now);
        static::assertSame(intdiv($now, 30), $w, 'returns the matched window number');
    }

    public function testVerifyRejectsTwoWindowsAwayWrongSecretAndGarbage(): void
    {
        $now = 1_700_000_000;
        [, $code] = $this->parse($this->service->wireToken('T1', self::SECRET, 30, $now));

        static::assertNull($this->service->verify($code, self::SECRET, 30, $now + 90), 'beyond ±1 drift');
        static::assertNull($this->service->verify($code, str_repeat('a', 64), 30, $now), 'wrong secret');
        static::assertNull($this->service->verify('zzzz', self::SECRET, 30, $now), 'malformed code');
    }

    public function testWireTokenParsesBackAndRejectsJunk(): void
    {
        $wire = $this->service->wireToken('FIB-DEMO-1', self::SECRET, 30, 1_700_000_000);
        $parsed = $this->service->parseWireToken($wire);

        static::assertNotNull($parsed);
        static::assertSame('FIB-DEMO-1', $parsed[0]);
        static::assertNull($this->service->parseWireToken('not-a-wire'));
        static::assertNull($this->service->parseWireToken('FIBR1:T1:short'), 'wrong code length');
        static::assertNull($this->service->parseWireToken(self::SECRET), 'a bare static token is not a rotating wire');
    }

    public function testIntervalIsClampedToASaneRange(): void
    {
        static::assertSame(30, $this->service->normalizeInterval(null));
        static::assertSame(30, $this->service->normalizeInterval(3), 'too short → default');
        static::assertSame(120, $this->service->normalizeInterval(9999), 'too long → cap');
        static::assertSame(60, $this->service->normalizeInterval(60));
    }

    public function testSecondsUntilNextWindowCountsDownToTheBoundary(): void
    {
        // 1_700_000_010 is divisible by 30 → sits exactly on a window edge.
        static::assertSame(30, $this->service->secondsUntilNextWindow(30, 1_700_000_010), 'exactly on a boundary → full window');
        static::assertSame(25, $this->service->secondsUntilNextWindow(30, 1_700_000_015));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $wire): array
    {
        $parsed = $this->service->parseWireToken($wire);
        static::assertNotNull($parsed);

        return $parsed;
    }
}
