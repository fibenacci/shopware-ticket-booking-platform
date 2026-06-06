<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Availability;

class AvailabilityResult
{
    public function __construct(
        private readonly bool $available,
        private readonly int $capacity,
        private readonly int $reservedQuantity,
        private readonly int $requestedQuantity,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getReservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    public function getRequestedQuantity(): int
    {
        return $this->requestedQuantity;
    }

    /**
     * @return array{available: bool, capacity: int, reservedQuantity: int, requestedQuantity: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'available' => $this->available,
            'capacity' => $this->capacity,
            'reservedQuantity' => $this->reservedQuantity,
            'requestedQuantity' => $this->requestedQuantity,
        ];
    }
}
