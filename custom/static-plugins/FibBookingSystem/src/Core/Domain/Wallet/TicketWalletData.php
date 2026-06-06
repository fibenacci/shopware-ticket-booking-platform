<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use DateTimeImmutable;

final class TicketWalletData
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $ticketNumber,
        public readonly string $bookingNumber,
        public readonly string $status,
        public readonly string $qrPayload,
        public readonly string $resourceName,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly int $quantity,
        public readonly ?string $customerName,
    ) {
    }
}
