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

    protected ?DateTimeInterface $validFrom = null;

    protected string $entryPolicy = 'single';

    protected ?int $maxEntriesPerDay = null;

    protected ?string $validityAnchor = null;

    protected ?string $validityDuration = null;

    protected ?string $seatLabel = null;

    protected ?string $replacedTicketId = null;

    protected bool $rotatingQrEnabled = false;

    protected ?int $rotatingQrInterval = null;

    protected ?string $ownerCustomerId = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $payload = null;

    protected ?BookingReservationEntity $reservation = null;

    public function getValidFrom(): ?DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?DateTimeInterface $validFrom): void
    {
        $this->validFrom = $validFrom;
    }

    public function getEntryPolicy(): string
    {
        return $this->entryPolicy;
    }

    public function setEntryPolicy(string $entryPolicy): void
    {
        $this->entryPolicy = $entryPolicy;
    }

    public function getMaxEntriesPerDay(): ?int
    {
        return $this->maxEntriesPerDay;
    }

    public function setMaxEntriesPerDay(?int $maxEntriesPerDay): void
    {
        $this->maxEntriesPerDay = $maxEntriesPerDay;
    }

    public function getValidityAnchor(): ?string
    {
        return $this->validityAnchor;
    }

    public function setValidityAnchor(?string $validityAnchor): void
    {
        $this->validityAnchor = $validityAnchor;
    }

    public function getValidityDuration(): ?string
    {
        return $this->validityDuration;
    }

    public function setValidityDuration(?string $validityDuration): void
    {
        $this->validityDuration = $validityDuration;
    }

    public function getSeatLabel(): ?string
    {
        return $this->seatLabel;
    }

    public function setSeatLabel(?string $seatLabel): void
    {
        $this->seatLabel = $seatLabel;
    }

    public function getReplacedTicketId(): ?string
    {
        return $this->replacedTicketId;
    }

    public function setReplacedTicketId(?string $replacedTicketId): void
    {
        $this->replacedTicketId = $replacedTicketId;
    }

    public function getRotatingQrEnabled(): bool
    {
        return $this->rotatingQrEnabled;
    }

    public function setRotatingQrEnabled(bool $rotatingQrEnabled): void
    {
        $this->rotatingQrEnabled = $rotatingQrEnabled;
    }

    public function getRotatingQrInterval(): ?int
    {
        return $this->rotatingQrInterval;
    }

    public function setRotatingQrInterval(?int $rotatingQrInterval): void
    {
        $this->rotatingQrInterval = $rotatingQrInterval;
    }

    public function getOwnerCustomerId(): ?string
    {
        return $this->ownerCustomerId;
    }

    public function setOwnerCustomerId(?string $ownerCustomerId): void
    {
        $this->ownerCustomerId = $ownerCustomerId;
    }

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

    public function getReservation(): ?BookingReservationEntity
    {
        return $this->reservation;
    }

    public function setReservation(?BookingReservationEntity $reservation): void
    {
        $this->reservation = $reservation;
    }
}
