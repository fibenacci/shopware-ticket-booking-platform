<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Builds an RFC 5545 iCalendar (.ics) invite for a booking's time slot, so the
 * confirmation mail carries an event the customer can add — and, when an
 * organizer address is known, accept/decline (METHOD:REQUEST with an ATTENDEE
 * triggers the RSVP buttons in Outlook / Apple Mail / Gmail).
 *
 * Pure and unit-tested: it takes plain values and returns the .ics text. The
 * subscriber gathers the data (resource name, organizer address) and attaches
 * the result to the mail.
 */
class IcsCalendarGenerator
{
    private const PRODID = '-//FIB Booking System//Booking Invite//EN';

    public function createInvite(
        string $uid,
        string $summary,
        DateTimeInterface $start,
        DateTimeInterface $end,
        DateTimeInterface $now,
        CalendarContact $attendee,
        ?CalendarContact $organizer = null,
        ?string $description = null,
        ?string $location = null,
    ): string {
        // An invite (accept/decline) needs an organizer; without one we still
        // emit a valid "add to calendar" event via METHOD:PUBLISH.
        $isRequest = $organizer !== null && $organizer->email !== '';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($isRequest ? 'REQUEST' : 'PUBLISH'),
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'SEQUENCE:0',
            'DTSTAMP:' . $this->utc($now),
            'DTSTART:' . $this->utc($start),
            'DTEND:' . $this->utc($end),
            'SUMMARY:' . $this->escape($summary),
        ];

        if ($description !== null && $description !== '') {
            $lines[] = 'DESCRIPTION:' . $this->escape($description);
        }

        if ($location !== null && $location !== '') {
            $lines[] = 'LOCATION:' . $this->escape($location);
        }

        if ($isRequest && $organizer !== null) {
            $lines[] = sprintf('ORGANIZER;CN=%s:mailto:%s', $this->escapeParam($organizer->displayName()), $organizer->email);
            $lines[] = sprintf(
                'ATTENDEE;CN=%s;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:%s',
                $this->escapeParam($attendee->displayName()),
                $attendee->email,
            );
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545: CRLF line endings, lines folded at 75 octets.
        $folded = array_map(fn (string $line): string => $this->fold($line), $lines);

        return implode("\r\n", $folded) . "\r\n";
    }

    private function utc(DateTimeInterface $value): string
    {
        return (new DateTimeImmutable('@' . $value->getTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Escapes a TEXT value per RFC 5545 §3.3.11 (backslash, semicolon, comma,
     * newline). Carriage returns are dropped so a multi-line value cannot break
     * the line structure.
     */
    private function escape(string $value): string
    {
        $value = str_replace("\r", '', $value);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value,
        );
    }

    /**
     * Escapes a parameter value (e.g. CN) — quote when it contains characters
     * that would otherwise terminate the parameter.
     */
    private function escapeParam(string $value): string
    {
        $value = str_replace(["\r", "\n", '"'], ['', '', ''], $value);

        return preg_match('/[;:,]/', $value) === 1 ? '"' . $value . '"' : $value;
    }

    /**
     * Folds a content line to 75 octets, continuation lines prefixed with a
     * single space (RFC 5545 §3.1). Operates on bytes to stay within the octet
     * limit for multi-byte UTF-8 without splitting a code point.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        $current = '';

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            // +1 leaves room for the leading space on continuation lines.
            if (strlen($current) + strlen($char) > 74) {
                $folded .= ($folded === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $folded . ($folded === '' ? '' : "\r\n ") . $current;
    }
}
