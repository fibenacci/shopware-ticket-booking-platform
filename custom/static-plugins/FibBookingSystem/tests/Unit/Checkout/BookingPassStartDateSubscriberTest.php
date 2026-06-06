<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Checkout;

use FibBookingSystem\Checkout\Cart\BookingPassStartDateSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The buy-form start date must reach the line item payload ONLY as a clean,
 * future YYYY-MM-DD value — everything else is dropped silently (the cart
 * processor decides whether a date is required).
 */
class BookingPassStartDateSubscriberTest extends TestCase
{
    public function testValidFutureDateIsCopiedToThePayload(): void
    {
        $lineItem = $this->dispatchWithRequestValue('2099-06-15');

        static::assertSame('2099-06-15', $lineItem->getPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY));
    }

    public function testPastDateIsDropped(): void
    {
        $lineItem = $this->dispatchWithRequestValue('2020-01-01');

        static::assertFalse($lineItem->hasPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY));
    }

    public function testMalformedValuesAreDropped(): void
    {
        foreach (['15.06.2099', '2099-6-1', 'tomorrow', '<script>', ''] as $value) {
            $lineItem = $this->dispatchWithRequestValue($value);

            static::assertFalse(
                $lineItem->hasPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY),
                sprintf('"%s" must not reach the payload', $value),
            );
        }
    }

    public function testNonProductLineItemsAreIgnored(): void
    {
        $lineItem = $this->dispatchWithRequestValue('2099-06-15', LineItem::PROMOTION_LINE_ITEM_TYPE);

        static::assertFalse($lineItem->hasPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY));
    }

    public function testMissingRequestIsHarmless(): void
    {
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex());
        $subscriber = new BookingPassStartDateSubscriber(new RequestStack());

        $subscriber->onLineItemAdded($this->createEvent($lineItem));

        static::assertFalse($lineItem->hasPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY));
    }

    private function dispatchWithRequestValue(
        string $value,
        string $lineItemType = LineItem::PRODUCT_LINE_ITEM_TYPE,
    ): LineItem {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(request: [BookingPassStartDateSubscriber::PAYLOAD_KEY => $value]));

        $lineItem = new LineItem(Uuid::randomHex(), $lineItemType, Uuid::randomHex());

        (new BookingPassStartDateSubscriber($requestStack))->onLineItemAdded($this->createEvent($lineItem));

        return $lineItem;
    }

    private function createEvent(LineItem $lineItem): BeforeLineItemAddedEvent
    {
        return new BeforeLineItemAddedEvent(
            $lineItem,
            new Cart('test-cart'),
            $this->createStub(SalesChannelContext::class),
            false,
        );
    }
}
