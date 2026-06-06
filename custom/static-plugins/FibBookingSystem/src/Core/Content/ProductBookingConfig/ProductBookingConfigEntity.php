<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\ProductBookingConfig;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ProductBookingConfigEntity extends Entity
{
    use EntityIdTrait;

    protected string $productId;

    protected string $productVersionId;

    protected string $resourceId;

    protected bool $enabled = false;

    protected int $slotMinutes = 60;

    protected ?ProductEntity $product = null;

    protected ?BookingResourceEntity $resource = null;

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductVersionId(): string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(string $productVersionId): void
    {
        $this->productVersionId = $productVersionId;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getSlotMinutes(): int
    {
        return $this->slotMinutes;
    }

    public function setSlotMinutes(int $slotMinutes): void
    {
        $this->slotMinutes = $slotMinutes;
    }
}
