<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

final class TicketWalletData
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $ticketNumber,
        public readonly string $bookingNumber,
        public readonly string $status,
        public readonly string $qrPayload,
        public readonly BookingWindow $window,
        public readonly ?string $customerName,
        public readonly ?string $seatLabel = null,
        public readonly bool $rotating = false,
    ) {
    }
}
