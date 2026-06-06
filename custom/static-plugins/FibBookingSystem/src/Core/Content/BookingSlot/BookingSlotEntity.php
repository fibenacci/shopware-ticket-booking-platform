<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSlot;

use DateTimeInterface;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingSlotEntity extends Entity
{
    use EntityIdTrait;

    protected string $resourceId;

    protected DateTimeInterface $startsAt;

    protected DateTimeInterface $endsAt;

    protected int $capacity;

    protected bool $active;

    protected ?BookingResourceEntity $resource = null;

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function getStartsAt(): DateTimeInterface
    {
        return $this->startsAt;
    }

    public function setStartsAt(DateTimeInterface $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getEndsAt(): DateTimeInterface
    {
        return $this->endsAt;
    }

    public function setEndsAt(DateTimeInterface $endsAt): void
    {
        $this->endsAt = $endsAt;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): void
    {
        $this->capacity = $capacity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getResource(): ?BookingResourceEntity
    {
        return $this->resource;
    }

    public function setResource(?BookingResourceEntity $resource): void
    {
        $this->resource = $resource;
    }
}
