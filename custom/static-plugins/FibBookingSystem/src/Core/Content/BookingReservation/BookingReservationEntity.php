<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingReservation;

use DateTimeInterface;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldEntity;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BookingReservationEntity extends Entity
{
    use EntityIdTrait;

    protected string $resourceId;

    protected ?string $orderId = null;

    protected ?string $orderVersionId = null;

    protected ?string $orderLineItemId = null;

    protected ?string $orderLineItemVersionId = null;

    protected ?string $customerId = null;

    protected ?string $holdId = null;

    protected string $bookingNumber;

    protected DateTimeInterface $startsAt;

    protected DateTimeInterface $endsAt;

    protected int $quantity = 1;

    protected string $status;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $payload = null;

    protected ?BookingResourceEntity $resource = null;

    protected ?OrderEntity $order = null;

    protected ?OrderLineItemEntity $orderLineItem = null;

    protected ?CustomerEntity $customer = null;

    protected ?BookingHoldEntity $hold = null;

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getResource(): ?BookingResourceEntity
    {
        return $this->resource;
    }

    public function setResource(?BookingResourceEntity $resource): void
    {
        $this->resource = $resource;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function setResourceId(string $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function getBookingNumber(): string
    {
        return $this->bookingNumber;
    }

    public function setBookingNumber(string $bookingNumber): void
    {
        $this->bookingNumber = $bookingNumber;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): void
    {
        $this->payload = $payload;
    }
}
