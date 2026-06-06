<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingTicket;

use DateTimeInterface;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingTicketEntity extends Entity
{
    use EntityIdTrait;

    protected string $reservationId;

    protected string $ticketNumber;

    protected string $scanTokenHash;

    protected string $status;

    protected DateTimeInterface $issuedAt;

    protected ?DateTimeInterface $sentAt = null;

    protected ?DateTimeInterface $scannedAt = null;

    protected ?DateTimeInterface $expiresAt = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $payload = null;

    protected ?BookingReservationEntity $reservation = null;

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function setReservationId(string $reservationId): void
    {
        $this->reservationId = $reservationId;
    }

    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    public function setTicketNumber(string $ticketNumber): void
    {
        $this->ticketNumber = $ticketNumber;
    }

    public function getScanTokenHash(): string
    {
        return $this->scanTokenHash;
    }

    public function setScanTokenHash(string $scanTokenHash): void
    {
        $this->scanTokenHash = $scanTokenHash;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getIssuedAt(): DateTimeInterface
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(DateTimeInterface $issuedAt): void
    {
        $this->issuedAt = $issuedAt;
    }

    public function getScannedAt(): ?DateTimeInterface
    {
        return $this->scannedAt;
    }

    public function setScannedAt(?DateTimeInterface $scannedAt): void
    {
        $this->scannedAt = $scannedAt;
    }
}
