<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Validity;

use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Validity\EntryPolicy;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use FibBookingSystem\Core\Domain\Validity\ValidityAnchor;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;

class TicketValidityResolverTest extends TestCase
{
    private TicketValidityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TicketValidityResolver();
    }

    public function testNoConfigFallsBackToLegacySlotSingle(): void
    {
        $validity = $this->resolver->resolve(null);

        static::assertNull($validity->validFrom);
        static::assertNull($validity->expiresAt);
        static::assertSame(EntryPolicy::SINGLE, $validity->entryPolicy);
        static::assertNull($validity->maxEntriesPerDay);
    }

    public function testSlotModeCarriesEntryPolicyButNoWindow(): void
    {
        $validity = $this->resolver->resolve($this->config(mode: 'slot', entryPolicy: 'multi'));

        static::assertNull($validity->expiresAt);
        static::assertSame(EntryPolicy::MULTI, $validity->entryPolicy);
    }

    public function testPurchaseAnchorComputesWindowFromNow(): void
    {
        $now = new DateTimeImmutable('2026-06-01 12:00:00');

        $validity = $this->resolver->resolve(
            $this->config(mode: 'period', duration: 'P1M', anchor: 'purchase', entryPolicy: 'multi'),
            now: $now,
        );

        static::assertEquals($now, $validity->validFrom);
        static::assertEquals(new DateTimeImmutable('2026-07-01 12:00:00'), $validity->expiresAt);
        static::assertSame('P1M', $validity->duration);
    }

    public function testCustomerAnchorUsesTheChosenStart(): void
    {
        $start = new DateTimeImmutable('2026-09-01 00:00:00');

        $validity = $this->resolver->resolve(
            $this->config(mode: 'period', duration: 'P1Y', anchor: 'customer', entryPolicy: 'multi', maxEntriesPerDay: 10),
            customerStart: $start,
        );

        static::assertEquals($start, $validity->validFrom);
        static::assertEquals(new DateTimeImmutable('2027-09-01 00:00:00'), $validity->expiresAt);
        static::assertSame(10, $validity->maxEntriesPerDay);
    }

    public function testCustomerAnchorWithoutStartIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('validityStart');

        $this->resolver->resolve($this->config(mode: 'period', duration: 'P1M', anchor: 'customer'));
    }

    public function testFirstUseAnchorStaysUnactivated(): void
    {
        $validity = $this->resolver->resolve(
            $this->config(mode: 'period', duration: 'P1D', anchor: 'first_use', entryPolicy: 'multi'),
        );

        static::assertNull($validity->validFrom);
        static::assertNull($validity->expiresAt);
        static::assertSame(ValidityAnchor::FIRST_USE, $validity->anchor);
        static::assertSame('P1D', $validity->duration);
    }

    public function testPeriodWithoutDurationIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('validityDuration');

        $this->resolver->resolve($this->config(mode: 'period'));
    }

    public function testMalformedDurationIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('not a valid ISO-8601 duration');

        $this->resolver->resolve($this->config(mode: 'period', duration: 'one month'));
    }

    public function testUnknownEntryPolicyIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('entryPolicy');

        $this->resolver->resolve($this->config(mode: 'slot', entryPolicy: 'sometimes'));
    }

    public function testNonPositiveMaxEntriesAreNormalizedToNull(): void
    {
        $validity = $this->resolver->resolve($this->config(mode: 'slot', maxEntriesPerDay: 0));

        static::assertNull($validity->maxEntriesPerDay);
    }

    /**
     * @return array{validity_mode: string|null, validity_duration: string|null, validity_anchor: string|null, entry_policy: string|null, max_entries_per_day: int|string|null}
     */
    private function config(
        ?string $mode = null,
        ?string $duration = null,
        ?string $anchor = null,
        ?string $entryPolicy = null,
        int|string|null $maxEntriesPerDay = null,
    ): array {
        return [
            'validity_mode' => $mode,
            'validity_duration' => $duration,
            'validity_anchor' => $anchor,
            'entry_policy' => $entryPolicy,
            'max_entries_per_day' => $maxEntriesPerDay,
        ];
    }
}
