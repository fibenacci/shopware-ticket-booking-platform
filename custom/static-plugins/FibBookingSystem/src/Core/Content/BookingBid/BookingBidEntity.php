<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingBid;

use FibBookingSystem\Core\Content\BookingListing\BookingListingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingBidEntity extends Entity
{
    use EntityIdTrait;

    protected string $listingId;

    protected string $customerId;

    protected float $amount = 0.0;

    protected ?BookingListingEntity $listing = null;

    public function getListingId(): string
    {
        return $this->listingId;
    }

    public function setListingId(string $listingId): void
    {
        $this->listingId = $listingId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function getListing(): ?BookingListingEntity
    {
        return $this->listing;
    }

    public function setListing(?BookingListingEntity $listing): void
    {
        $this->listing = $listing;
    }
}
