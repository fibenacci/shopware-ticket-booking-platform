<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateTimeImmutable;
use Exception;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigEntity;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use FibBookingSystem\Core\Domain\Validity\ValidityMode;
use FibBookingSystem\FibBookingException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;

/**
 * Creates reservations for PASS products (validity mode `period` or
 * `unlimited`) when an order is placed. Passes need no calendar slot and no
 * hold — they are bought with the standard product buy box; the ticket's
 * actual validity window is computed at issue time by the
 * TicketValidityResolver (and, for the `first_use` anchor, activated by the
 * first scan).
 *
 * The reservation's starts_at/ends_at are a best-effort mirror of the
 * validity window for reporting; capacity accounting deliberately does NOT
 * apply to passes (slots stay the instrument for capacity-bound offers).
 */
class BookingPassReservationService
{
    /**
     * @param EntityRepository<OrderCollection>                $orderRepository
     * @param EntityRepository<ProductBookingConfigCollection> $productConfigRepository
     * @param EntityRepository<BookingReservationCollection>   $reservationRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productConfigRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly TicketValidityResolver $validityResolver,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of pass reservations created
     */
    public function createForOrder(string $orderId, Context $context): int
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return 0;
        }

        $configs = $this->fetchPassConfigs($order, $context);
        if ($configs === []) {
            return 0;
        }

        $created = 0;

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $config = $configs[$lineItem->getProductId() ?? ''] ?? null;

            if ($config === null || $this->hasReservation($lineItem->getId(), $context)) {
                continue;
            }

            try {
                $this->createPassReservation($order, $lineItem, $config, $context);
                ++$created;
            } catch (FibBookingException $exception) {
                // Misconfiguration (e.g. customer anchor without a start
                // date) must never abort the placed order — surface it in
                // the logs so the operator can issue the ticket manually.
                $this->logger->error('Pass reservation failed for line item {lineItemId}: {message}', [
                    'lineItemId' => $lineItem->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        return $created;
    }

    /**
     * Enabled pass configs (period/unlimited) for the order's products,
     * keyed by product id. Slot products keep the hold→reservation path.
     *
     * @return array<string, ProductBookingConfigEntity>
     */
    private function fetchPassConfigs(OrderEntity $order, Context $context): array
    {
        $productIds = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($lineItem->getProductId() !== null) {
                $productIds[] = $lineItem->getProductId();
            }
        }

        if ($productIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productId', array_unique($productIds)));
        $criteria->addFilter(new EqualsFilter('enabled', true));
        $criteria->addFilter(new EqualsAnyFilter('validityMode', [ValidityMode::PERIOD, ValidityMode::UNLIMITED]));

        $configs = [];
        /** @var ProductBookingConfigEntity $config */
        foreach ($this->productConfigRepository->search($criteria, $context)->getEntities() as $config) {
            $configs[$config->getProductId()] = $config;
        }

        return $configs;
    }

    private function hasReservation(string $orderLineItemId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderLineItemId', $orderLineItemId));

        return $this->reservationRepository->searchIds($criteria, $context)->firstId() !== null;
    }

    private function createPassReservation(
        OrderEntity $order,
        OrderLineItemEntity $lineItem,
        ProductBookingConfigEntity $config,
        Context $context,
    ): void {
        $customerStart = $this->extractCustomerStart($lineItem);

        // Resolve once here to validate the configuration/payload early and
        // mirror the window onto the reservation; the ticket snapshot is
        // resolved again (single source of truth) at issue time.
        $validity = $this->validityResolver->resolve([
            'validity_mode' => $config->getValidityMode(),
            'validity_duration' => $config->getValidityDuration(),
            'validity_anchor' => $config->getValidityAnchor(),
            'entry_policy' => $config->getEntryPolicy(),
            'max_entries_per_day' => $config->getMaxEntriesPerDay(),
        ], $customerStart);

        $now = new DateTimeImmutable();
        // first_use / unlimited have no window yet — starts_at mirrors the
        // purchase; ends_at falls back to starts_at (open-ended, the ticket
        // carries the real validity).
        $startsAt = $validity->validFrom ?? $now;
        $endsAt = $validity->expiresAt ?? $startsAt;

        $payload = ['pass' => true];
        if ($customerStart !== null) {
            $payload['validityStart'] = $customerStart->format(\DATE_ATOM);
        }

        $this->reservationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'resourceId' => $config->getResourceId(),
                'orderId' => $order->getId(),
                'orderVersionId' => $order->getVersionId(),
                'orderLineItemId' => $lineItem->getId(),
                'orderLineItemVersionId' => $lineItem->getVersionId(),
                'customerId' => $order->getOrderCustomer()?->getCustomerId(),
                'bookingNumber' => $this->numberRangeValueGenerator->getValue(
                    BookingReservationService::NUMBER_RANGE_TYPE,
                    $context,
                    $order->getSalesChannelId(),
                ),
                'startsAt' => $startsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'endsAt' => $endsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'quantity' => $lineItem->getQuantity(),
                'status' => 'pending_payment',
                'payload' => $payload,
            ],
        ], $context);
    }

    /**
     * Customer-chosen start date (the `customer` anchor) from the line item
     * payload — set by the storefront buy form as `fibBookingValidityStart`.
     */
    private function extractCustomerStart(OrderLineItemEntity $lineItem): ?DateTimeImmutable
    {
        $payload = $lineItem->getPayload() ?? [];
        $start = $payload['fibBookingValidityStart'] ?? null;

        if (!is_string($start) || $start === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($start);
        } catch (Exception) {
            return null;
        }
    }
}
