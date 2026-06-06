<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeat;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingSeatEntity extends Entity
{
    use EntityIdTrait;

    protected string $resourceId;

    protected string $rowLabel;

    protected string $seatLabel;

    protected int $posX = 0;

    protected int $posY = 0;

    protected ?string $category = null;

    protected bool $active = true;

    protected ?BookingResourceEntity $resource = null;

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function getRowLabel(): string
    {
        return $this->rowLabel;
    }

    public function setRowLabel(string $rowLabel): void
    {
        $this->rowLabel = $rowLabel;
    }

    public function getSeatLabel(): string
    {
        return $this->seatLabel;
    }

    public function setSeatLabel(string $seatLabel): void
    {
        $this->seatLabel = $seatLabel;
    }

    public function getPosX(): int
    {
        return $this->posX;
    }

    public function setPosX(int $posX): void
    {
        $this->posX = $posX;
    }

    public function getPosY(): int
    {
        return $this->posY;
    }

    public function setPosY(int $posY): void
    {
        $this->posY = $posY;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
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
