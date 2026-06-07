<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

/**
 * An organizer or attendee on an iCalendar invite — an email with an optional
 * display name.
 */
final class CalendarContact
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
