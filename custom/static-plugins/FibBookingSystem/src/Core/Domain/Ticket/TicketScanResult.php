<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

final class TicketScanResult
{
    public const VALID = 'valid';
    public const ALREADY_SCANNED = 'already_scanned';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $ticketNumber = null,
        public readonly ?string $bookingNumber = null,
        public readonly ?string $scannedAt = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->verdict === self::VALID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'valid' => $this->isValid(),
            'ticket' => $this->ticketNumber === null ? null : [
                'ticketNumber' => $this->ticketNumber,
                'bookingNumber' => $this->bookingNumber,
                'scannedAt' => $this->scannedAt,
            ],
        ];
    }
}
