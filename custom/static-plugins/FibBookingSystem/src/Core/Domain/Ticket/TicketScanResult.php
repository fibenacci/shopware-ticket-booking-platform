<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

final class TicketScanResult
{
    public const VALID = 'valid';
    public const ALREADY_SCANNED = 'already_scanned';
    public const CHECKED_OUT = 'checked_out';
    public const NOT_CHECKED_IN = 'not_checked_in';
    public const NOT_YET_VALID = 'not_yet_valid';
    public const ENTRY_LIMIT_REACHED = 'entry_limit_reached';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const NOT_FOUND = 'not_found';
    public const INVALID_CODE = 'invalid_code';

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $ticketNumber = null,
        public readonly ?string $bookingNumber = null,
        public readonly ?string $scannedAt = null,
        public readonly string $direction = ScanDirection::CHECK_IN,
        public readonly ?string $seatLabel = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->verdict === self::VALID || $this->verdict === self::CHECKED_OUT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'valid' => $this->isValid(),
            'direction' => $this->direction,
            'ticket' => $this->ticketNumber === null ? null : [
                'ticketNumber' => $this->ticketNumber,
                'bookingNumber' => $this->bookingNumber,
                'scannedAt' => $this->scannedAt,
                'seatLabel' => $this->seatLabel,
            ],
        ];
    }
}
