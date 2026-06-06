<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateTimeInterface;

class BookingHold
{
    public function __construct(
        private readonly string $id,
        private readonly string $token,
        private readonly DateTimeInterface $expiresAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): DateTimeInterface
    {
        return $this->expiresAt;
    }
}
