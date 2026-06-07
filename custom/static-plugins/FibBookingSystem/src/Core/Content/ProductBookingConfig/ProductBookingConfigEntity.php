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

    protected string $validityMode = 'slot';

    protected ?string $validityDuration = null;

    protected ?string $validityAnchor = null;

    protected string $entryPolicy = 'single';

    protected ?int $maxEntriesPerDay = null;

    protected bool $rotatingQrEnabled = false;

    protected ?int $rotatingQrInterval = null;

    protected bool $calendarInviteEnabled = true;

    protected ?ProductEntity $product = null;

    protected ?BookingResourceEntity $resource = null;

    public function getValidityMode(): string
    {
        return $this->validityMode;
    }

    public function setValidityMode(string $validityMode): void
    {
        $this->validityMode = $validityMode;
    }

    public function getValidityDuration(): ?string
    {
        return $this->validityDuration;
    }

    public function setValidityDuration(?string $validityDuration): void
    {
        $this->validityDuration = $validityDuration;
    }

    public function getValidityAnchor(): ?string
    {
        return $this->validityAnchor;
    }

    public function setValidityAnchor(?string $validityAnchor): void
    {
        $this->validityAnchor = $validityAnchor;
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

    public function getCalendarInviteEnabled(): bool
    {
        return $this->calendarInviteEnabled;
    }

    public function setCalendarInviteEnabled(bool $calendarInviteEnabled): void
    {
        $this->calendarInviteEnabled = $calendarInviteEnabled;
    }

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

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
