<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

class BookingTicket
{
    public function __construct(
        private readonly string $id,
        private readonly string $ticketNumber,
        private readonly string $scanToken,
        private readonly string $qrPayload,
        private readonly string $qrCodeDataUri,
        private readonly ?string $seatLabel = null,
        private readonly bool $calendarInviteEnabled = true,
    ) {
    }

    /** Numbered seating only — null for pool tickets. */
    public function getSeatLabel(): ?string
    {
        return $this->seatLabel;
    }

    /** Whether the confirmation mail attaches an .ics invite (per-product). */
    public function isCalendarInviteEnabled(): bool
    {
        return $this->calendarInviteEnabled;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    public function getScanToken(): string
    {
        return $this->scanToken;
    }

    public function getQrPayload(): string
    {
        return $this->qrPayload;
    }

    public function getQrCodeDataUri(): string
    {
        return $this->qrCodeDataUri;
    }
}
