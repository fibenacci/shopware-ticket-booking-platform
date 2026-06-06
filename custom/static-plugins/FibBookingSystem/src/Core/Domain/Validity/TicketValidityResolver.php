<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Validity;

use DateInterval;
use DateTimeImmutable;
use Exception;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;

/**
 * Resolves a product's booking configuration into the validity snapshot for
 * one ticket. Pure computation — no I/O, fully unit-testable.
 *
 * @phpstan-type ValidityConfig array{validity_mode: string|null, validity_duration: string|null, validity_anchor: string|null, entry_policy: string|null, max_entries_per_day: int|string|null}
 */
class TicketValidityResolver
{
    /**
     * @param ValidityConfig|null    $config        raw config columns (null: product without booking config → legacy slot behavior)
     * @param DateTimeImmutable|null $customerStart start date chosen by the customer (required for the `customer` anchor)
     * @param DateTimeImmutable|null $now           injectable clock for tests
     */
    public function resolve(
        ?array $config,
        ?DateTimeImmutable $customerStart = null,
        ?DateTimeImmutable $now = null,
    ): TicketValidity {
        $entryPolicy = $this->resolveEntryPolicy($config['entry_policy'] ?? null);
        $maxEntriesPerDay = $this->resolveMaxEntriesPerDay($config['max_entries_per_day'] ?? null);
        $mode = $config['validity_mode'] ?? ValidityMode::SLOT;

        if ($config === null || $mode === ValidityMode::SLOT || $mode === ValidityMode::UNLIMITED) {
            // slot: expiry is governed by the booked Termin (caller-provided);
            // unlimited: never expires. Both need no window computation.
            return new TicketValidity(null, null, $entryPolicy, $maxEntriesPerDay);
        }

        if ($mode !== ValidityMode::PERIOD) {
            throw FibBookingException::invalidPayload('validityMode', sprintf('unknown mode "%s"', $mode));
        }

        $duration = $config['validity_duration'] ?? null;
        $interval = $this->parseDuration($duration);
        $anchor = $config['validity_anchor'] ?? ValidityAnchor::PURCHASE;

        return match ($anchor) {
            ValidityAnchor::PURCHASE => $this->window($now ?? UtcDateTime::now(), $interval, $entryPolicy, $maxEntriesPerDay, $anchor, $duration),
            ValidityAnchor::CUSTOMER => $this->window(
                $customerStart ?? throw FibBookingException::invalidPayload('validityStart', 'the customer anchor requires a start date'),
                $interval,
                $entryPolicy,
                $maxEntriesPerDay,
                $anchor,
                $duration,
            ),
            // Activated by the first successful check-in — see TicketScanService.
            ValidityAnchor::FIRST_USE => new TicketValidity(null, null, $entryPolicy, $maxEntriesPerDay, $anchor, $duration),
            default => throw FibBookingException::invalidPayload('validityAnchor', sprintf('unknown anchor "%s"', $anchor)),
        };
    }

    private function window(
        DateTimeImmutable $start,
        DateInterval $interval,
        string $entryPolicy,
        ?int $maxEntriesPerDay,
        string $anchor,
        ?string $duration,
    ): TicketValidity {
        return new TicketValidity($start, $start->add($interval), $entryPolicy, $maxEntriesPerDay, $anchor, $duration);
    }

    private function parseDuration(?string $duration): DateInterval
    {
        if ($duration === null || $duration === '') {
            throw FibBookingException::invalidPayload('validityDuration', 'period tickets require an ISO-8601 duration (e.g. P1D, P1M, P1Y)');
        }

        try {
            return new DateInterval($duration);
        } catch (Exception) {
            throw FibBookingException::invalidPayload('validityDuration', sprintf('"%s" is not a valid ISO-8601 duration', $duration));
        }
    }

    private function resolveEntryPolicy(?string $entryPolicy): string
    {
        if ($entryPolicy === null || $entryPolicy === '') {
            return EntryPolicy::SINGLE;
        }

        if (!in_array($entryPolicy, EntryPolicy::ALL, true)) {
            throw FibBookingException::invalidPayload('entryPolicy', sprintf('unknown policy "%s"', $entryPolicy));
        }

        return $entryPolicy;
    }

    private function resolveMaxEntriesPerDay(int|string|null $maxEntriesPerDay): ?int
    {
        if ($maxEntriesPerDay === null || $maxEntriesPerDay === '') {
            return null;
        }

        $value = (int) $maxEntriesPerDay;

        return $value > 0 ? $value : null;
    }
}
