<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart;

use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\Core\Domain\Validity\ValidityAnchor;
use FibBookingSystem\Core\Domain\Validity\ValidityMode;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Two cart-level guarantees:
 *
 * 1. Slot line items must reference a LIVING hold — expired/foreign holds
 *    block checkout (the no-overbooking promise extends into the cart).
 * 2. Customer-anchored pass products must carry a valid future start date
 *    (`fibBookingValidityStart` payload) — otherwise the order would be
 *    placed but no usable ticket could ever be issued.
 *
 * @phpstan-import-type ValidityConfig from \FibBookingSystem\Core\Domain\Validity\TicketValidityResolver
 */
class BookingCartProcessor implements CartProcessorInterface
{
    /**
     * @param EntityRepository<BookingHoldCollection>          $holdRepository
     * @param EntityRepository<ProductBookingConfigCollection> $productConfigRepository
     */
    public function __construct(
        private readonly EntityRepository $holdRepository,
        private readonly EntityRepository $productConfigRepository,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $this->validateHolds($toCalculate, $context->getContext());
        $this->validatePassStartDates($toCalculate, $context->getContext());
    }

    private function validateHolds(Cart $toCalculate, Context $context): void
    {
        foreach ($toCalculate->getLineItems()->getFlat() as $lineItem) {
            $bookingPayload = $this->extractBookingPayload($lineItem);

            if ($bookingPayload === null) {
                continue;
            }

            if ($this->isValidHold($bookingPayload['holdId'], $bookingPayload['holdToken'], $context)) {
                continue;
            }

            $toCalculate->addErrors(new GenericCartError(
                sprintf('fib-booking-hold-invalid-%s', $lineItem->getId()),
                'fib-booking.hold-invalid',
                [
                    'lineItemId' => $lineItem->getId(),
                ],
                Error::LEVEL_ERROR,
                true,
                false,
                true,
            ));
        }
    }

    /**
     * Customer-anchored passes need the start date BEFORE the order exists —
     * a missing/past date blocks checkout with an actionable message instead
     * of failing silently at ticket-issue time.
     */
    private function validatePassStartDates(Cart $toCalculate, Context $context): void
    {
        $productLineItems = [];
        foreach ($toCalculate->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $lineItem->getReferencedId() !== null) {
                $productLineItems[] = $lineItem;
            }
        }

        if ($productLineItems === []) {
            return;
        }

        $customerAnchoredProductIds = $this->fetchCustomerAnchoredProductIds(
            array_map(static fn (LineItem $lineItem): string => (string) $lineItem->getReferencedId(), $productLineItems),
            $context,
        );

        if ($customerAnchoredProductIds === []) {
            return;
        }

        foreach ($productLineItems as $lineItem) {
            if (!in_array($lineItem->getReferencedId(), $customerAnchoredProductIds, true)) {
                continue;
            }

            if ($this->hasValidStartDate($lineItem)) {
                continue;
            }

            $toCalculate->addErrors(new GenericCartError(
                sprintf('fib-booking-pass-start-missing-%s', $lineItem->getId()),
                'fibBookingPassStartMissing',
                [
                    'lineItemId' => $lineItem->getId(),
                ],
                Error::LEVEL_ERROR,
                true,
                false,
                true,
            ));
        }
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    private function fetchCustomerAnchoredProductIds(array $productIds, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productId', array_values(array_unique($productIds))));
        $criteria->addFilter(new EqualsFilter('enabled', true));
        $criteria->addFilter(new EqualsFilter('validityMode', ValidityMode::PERIOD));
        $criteria->addFilter(new EqualsFilter('validityAnchor', ValidityAnchor::CUSTOMER));

        $ids = [];
        foreach ($this->productConfigRepository->search($criteria, $context)->getEntities() as $config) {
            $ids[] = $config->getProductId();
        }

        return $ids;
    }

    private function hasValidStartDate(LineItem $lineItem): bool
    {
        $start = $lineItem->getPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY);

        if (!is_string($start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            return false;
        }

        if (!checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4))) {
            return false;
        }

        // Calendar-date comparison in UTC — `new DateTimeImmutable('today')`
        // would shift the boundary by the PHP default timezone offset.
        return $start >= UtcDateTime::now()->format('Y-m-d');
    }

    /**
     * @return array{holdId: string, holdToken: string}|null
     */
    private function extractBookingPayload(LineItem $lineItem): ?array
    {
        $payload = $lineItem->getPayloadValue('fibBooking');

        if (!is_array($payload)) {
            return null;
        }

        $holdId = $payload['holdId'] ?? null;
        $holdToken = $payload['holdToken'] ?? null;

        if (!is_string($holdId) || !is_string($holdToken) || $holdId === '' || $holdToken === '') {
            return null;
        }

        return [
            'holdId' => $holdId,
            'holdToken' => $holdToken,
        ];
    }

    private function isValidHold(string $holdId, string $holdToken, Context $context): bool
    {
        if (!Uuid::isValid($holdId)) {
            return false;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $holdId));
        $criteria->addFilter(new EqualsFilter('token', $holdToken));
        $criteria->addFilter(new EqualsFilter('status', 'active'));
        $criteria->addFilter(new RangeFilter('expiresAt', [
            // UTC + offset suffix, so the DAL compares the right instant even
            // when the PHP default timezone is not UTC.
            RangeFilter::GT => UtcDateTime::now()->format(\DATE_ATOM),
        ]));

        return $this->holdRepository->searchIds($criteria, $context)->firstId() !== null;
    }
}
