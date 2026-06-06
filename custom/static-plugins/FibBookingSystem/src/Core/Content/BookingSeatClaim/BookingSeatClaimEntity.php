<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeatClaim;

use FibBookingSystem\Core\Content\BookingHold\BookingHoldEntity;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Content\BookingSeat\BookingSeatEntity;
use FibBookingSystem\Core\Content\BookingSlot\BookingSlotEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingSeatClaimEntity extends Entity
{
    use EntityIdTrait;

    protected string $seatId;

    protected string $slotId;

    protected ?string $holdId = null;

    protected ?string $reservationId = null;

    protected ?BookingSeatEntity $seat = null;

    protected ?BookingSlotEntity $slot = null;

    protected ?BookingHoldEntity $hold = null;

    protected ?BookingReservationEntity $reservation = null;

    public function getSeatId(): string
    {
        return $this->seatId;
    }

    public function setSeatId(string $seatId): void
    {
        $this->seatId = $seatId;
    }

    public function getSlotId(): string
    {
        return $this->slotId;
    }

    public function setSlotId(string $slotId): void
    {
        $this->slotId = $slotId;
    }

    public function getHoldId(): ?string
    {
        return $this->holdId;
    }

    public function setHoldId(?string $holdId): void
    {
        $this->holdId = $holdId;
    }

    public function getReservationId(): ?string
    {
        return $this->reservationId;
    }

    public function setReservationId(?string $reservationId): void
    {
        $this->reservationId = $reservationId;
    }

    public function getSeat(): ?BookingSeatEntity
    {
        return $this->seat;
    }

    public function setSeat(?BookingSeatEntity $seat): void
    {
        $this->seat = $seat;
    }

    public function getSlot(): ?BookingSlotEntity
    {
        return $this->slot;
    }

    public function setSlot(?BookingSlotEntity $slot): void
    {
        $this->slot = $slot;
    }

    public function getHold(): ?BookingHoldEntity
    {
        return $this->hold;
    }

    public function setHold(?BookingHoldEntity $hold): void
    {
        $this->hold = $hold;
    }

    public function getReservation(): ?BookingReservationEntity
    {
        return $this->reservation;
    }

    public function setReservation(?BookingReservationEntity $reservation): void
    {
        $this->reservation = $reservation;
    }
}
