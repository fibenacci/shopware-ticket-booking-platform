<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Ticket;

use DateTimeImmutable;
use DateTimeZone;
use FibBookingSystem\Core\Domain\Ticket\CalendarContact;
use FibBookingSystem\Core\Domain\Ticket\IcsCalendarGenerator;
use PHPUnit\Framework\TestCase;

class IcsCalendarGeneratorTest extends TestCase
{
    private IcsCalendarGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IcsCalendarGenerator();
    }

    public function testBuildsARequestInviteWithOrganizerAndAttendee(): void
    {
        $ics = $this->generator->createInvite(
            uid: 'B-1001@shop.example.com',
            summary: 'Cinema — Hall 1',
            start: new DateTimeImmutable('2026-07-01 20:00:00', new DateTimeZone('Europe/Berlin')),
            end: new DateTimeImmutable('2026-07-01 22:00:00', new DateTimeZone('Europe/Berlin')),
            now: new DateTimeImmutable('2026-06-07 10:00:00', new DateTimeZone('UTC')),
            attendee: new CalendarContact('jane@example.com', 'Jane Doe'),
            organizer: new CalendarContact('shop@example.com', 'Demo Shop'),
            description: 'Booking B-1001 — 2 ticket(s).',
            location: 'Cinema — Hall 1',
        );

        static::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        static::assertStringEndsWith("END:VCALENDAR\r\n", $ics);

        // Assert content on the UNFOLDED form — how a parser reads it (long
        // lines like ATTENDEE are folded at 75 octets per RFC 5545).
        $unfolded = str_replace("\r\n ", '', $ics);
        static::assertStringContainsString('METHOD:REQUEST', $unfolded);
        static::assertStringContainsString('UID:B-1001@shop.example.com', $unfolded);
        static::assertStringContainsString('SUMMARY:Cinema — Hall 1', $unfolded);
        // Berlin 20:00 in July (CEST, +2) → 18:00 UTC.
        static::assertStringContainsString('DTSTART:20260701T180000Z', $unfolded);
        static::assertStringContainsString('DTEND:20260701T200000Z', $unfolded);
        static::assertStringContainsString('DTSTAMP:20260607T100000Z', $unfolded);
        static::assertStringContainsString('ORGANIZER;CN=Demo Shop:mailto:shop@example.com', $unfolded);
        static::assertStringContainsString(
            'ATTENDEE;CN=Jane Doe;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:jane@example.com',
            $unfolded,
        );
        static::assertStringContainsString('STATUS:CONFIRMED', $unfolded);
    }

    public function testFallsBackToPublishWithoutAnOrganizer(): void
    {
        $ics = $this->generator->createInvite(
            uid: 'B-1002@booking',
            summary: 'Workshop',
            start: new DateTimeImmutable('2026-07-02 09:00:00', new DateTimeZone('UTC')),
            end: new DateTimeImmutable('2026-07-02 11:00:00', new DateTimeZone('UTC')),
            now: new DateTimeImmutable('2026-06-07 10:00:00', new DateTimeZone('UTC')),
            attendee: new CalendarContact('jane@example.com', 'Jane Doe'),
            organizer: null,
        );

        static::assertStringContainsString('METHOD:PUBLISH', $ics);
        static::assertStringNotContainsString('ORGANIZER', $ics);
        static::assertStringNotContainsString('ATTENDEE', $ics);
    }

    public function testEscapesTextValuesPerRfc5545(): void
    {
        $ics = $this->generator->createInvite(
            uid: 'u',
            summary: 'A, B; C\\D',
            start: new DateTimeImmutable('2026-07-02 09:00:00', new DateTimeZone('UTC')),
            end: new DateTimeImmutable('2026-07-02 11:00:00', new DateTimeZone('UTC')),
            now: new DateTimeImmutable('2026-06-07 10:00:00', new DateTimeZone('UTC')),
            attendee: new CalendarContact('jane@example.com', 'Jane'),
            description: "line one\nline two",
        );

        static::assertStringContainsString('SUMMARY:A\\, B\\; C\\\\D', $ics);
        static::assertStringContainsString('DESCRIPTION:line one\\nline two', $ics);
    }

    public function testFoldsLongLinesAt75Octets(): void
    {
        $ics = $this->generator->createInvite(
            uid: 'u',
            summary: str_repeat('x', 200),
            start: new DateTimeImmutable('2026-07-02 09:00:00', new DateTimeZone('UTC')),
            end: new DateTimeImmutable('2026-07-02 11:00:00', new DateTimeZone('UTC')),
            now: new DateTimeImmutable('2026-06-07 10:00:00', new DateTimeZone('UTC')),
            attendee: new CalendarContact('jane@example.com', 'Jane'),
        );

        // A folded continuation line begins with CRLF + a single space.
        static::assertStringContainsString("\r\n x", $ics);
        foreach (explode("\r\n", $ics) as $line) {
            static::assertLessThanOrEqual(75, strlen($line), 'no content line exceeds 75 octets');
        }
    }
}
