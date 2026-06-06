<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart;

use DateTimeImmutable;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Carries the customer-chosen pass start date (the `customer` validity
 * anchor) from the buy form into the cart line item payload — from there it
 * flows into the order line item and finally into the reservation
 * ({@see \FibBookingSystem\Core\Domain\Reservation\BookingPassReservationService}).
 *
 * Strictly validated before it is stored: exactly `YYYY-MM-DD` and not in
 * the past. Whether the date is actually REQUIRED for the product is
 * enforced by the cart processor — this subscriber only transports clean
 * values.
 */
class BookingPassStartDateSubscriber implements EventSubscriberInterface
{
    public const PAYLOAD_KEY = 'fibBookingValidityStart';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onLineItemAdded',
        ];
    }

    public function onLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getLineItem();
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $start = $request?->request->get(self::PAYLOAD_KEY);

        if (!is_string($start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            return;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d|', $start);
        if ($date === false || $date < new DateTimeImmutable('today')) {
            return;
        }

        $lineItem->setPayloadValue(self::PAYLOAD_KEY, $date->format('Y-m-d'));
    }
}
