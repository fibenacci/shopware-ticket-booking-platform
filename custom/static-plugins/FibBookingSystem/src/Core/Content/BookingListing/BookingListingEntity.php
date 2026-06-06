<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingListing;

use DateTimeInterface;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingListingEntity extends Entity
{
    use EntityIdTrait;

    protected string $ticketId;

    protected string $sellerCustomerId;

    protected string $mode;

    protected string $status = 'active';

    protected float $askPrice = 0.0;

    protected ?float $reservePrice = null;

    protected ?float $minIncrement = null;

    protected ?DateTimeInterface $endsAt = null;

    protected ?string $soldToCustomerId = null;

    protected ?float $soldPrice = null;

    protected ?DateTimeInterface $soldAt = null;

    protected ?BookingTicketEntity $ticket = null;

    public function getTicketId(): string
    {
        return $this->ticketId;
    }

    public function setTicketId(string $ticketId): void
    {
        $this->ticketId = $ticketId;
    }

    public function getSellerCustomerId(): string
    {
        return $this->sellerCustomerId;
    }

    public function setSellerCustomerId(string $sellerCustomerId): void
    {
        $this->sellerCustomerId = $sellerCustomerId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getAskPrice(): float
    {
        return $this->askPrice;
    }

    public function setAskPrice(float $askPrice): void
    {
        $this->askPrice = $askPrice;
    }

    public function getReservePrice(): ?float
    {
        return $this->reservePrice;
    }

    public function setReservePrice(?float $reservePrice): void
    {
        $this->reservePrice = $reservePrice;
    }

    public function getMinIncrement(): ?float
    {
        return $this->minIncrement;
    }

    public function setMinIncrement(?float $minIncrement): void
    {
        $this->minIncrement = $minIncrement;
    }

    public function getEndsAt(): ?DateTimeInterface
    {
        return $this->endsAt;
    }

    public function setEndsAt(?DateTimeInterface $endsAt): void
    {
        $this->endsAt = $endsAt;
    }

    public function getSoldToCustomerId(): ?string
    {
        return $this->soldToCustomerId;
    }

    public function setSoldToCustomerId(?string $soldToCustomerId): void
    {
        $this->soldToCustomerId = $soldToCustomerId;
    }

    public function getSoldPrice(): ?float
    {
        return $this->soldPrice;
    }

    public function setSoldPrice(?float $soldPrice): void
    {
        $this->soldPrice = $soldPrice;
    }

    public function getSoldAt(): ?DateTimeInterface
    {
        return $this->soldAt;
    }

    public function setSoldAt(?DateTimeInterface $soldAt): void
    {
        $this->soldAt = $soldAt;
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
