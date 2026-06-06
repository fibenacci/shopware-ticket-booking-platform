<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingScanLog;

use FibBookingSystem\Core\Content\BookingTicket\BookingTicketEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingScanLogEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $ticketId = null;

    protected string $verdict;

    protected string $direction;

    protected ?string $tokenFingerprint = null;

    protected ?string $scannedBy = null;

    protected string $source;

    protected ?string $gate = null;

    protected ?BookingTicketEntity $ticket = null;

    public function getTicketId(): ?string
    {
        return $this->ticketId;
    }

    public function setTicketId(?string $ticketId): void
    {
        $this->ticketId = $ticketId;
    }

    public function getVerdict(): string
    {
        return $this->verdict;
    }

    public function setVerdict(string $verdict): void
    {
        $this->verdict = $verdict;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): void
    {
        $this->direction = $direction;
    }

    public function getTokenFingerprint(): ?string
    {
        return $this->tokenFingerprint;
    }

    public function setTokenFingerprint(?string $tokenFingerprint): void
    {
        $this->tokenFingerprint = $tokenFingerprint;
    }

    public function getScannedBy(): ?string
    {
        return $this->scannedBy;
    }

    public function setScannedBy(?string $scannedBy): void
    {
        $this->scannedBy = $scannedBy;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getGate(): ?string
    {
        return $this->gate;
    }

    public function setGate(?string $gate): void
    {
        $this->gate = $gate;
    }

    public function getTicket(): ?BookingTicketEntity
    {
        return $this->ticket;
    }

    public function setTicket(?BookingTicketEntity $ticket): void
    {
        $this->ticket = $ticket;
    }
}
