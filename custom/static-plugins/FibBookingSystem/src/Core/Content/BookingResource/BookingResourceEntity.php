<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingResource;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingResourceEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $productId = null;

    protected string $name;

    protected string $technicalName;

    protected int $capacity = 1;

    protected string $seatingMode = 'pool';

    protected bool $active = true;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $configuration = null;

    protected ?ProductEntity $product = null;

    public function getSeatingMode(): string
    {
        return $this->seatingMode;
    }

    public function setSeatingMode(string $seatingMode): void
    {
        $this->seatingMode = $seatingMode;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(string $technicalName): void
    {
        $this->technicalName = $technicalName;
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

    /**
     * @return array<string, mixed>|null
     */
    public function getConfiguration(): ?array
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed>|null $configuration
     */
    public function setConfiguration(?array $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
