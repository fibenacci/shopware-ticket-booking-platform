<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Checkout\Cart;

use FibBookingSystem\Checkout\Cart\BookingLineItemFactory;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;

class BookingLineItemFactoryTest extends TestCase
{
    public function testCreatesNonStackableProductLineItemWithBookingPayload(): void
    {
        $productId = Uuid::randomHex();
        $holdId = Uuid::randomHex();
        $holdToken = bin2hex(random_bytes(16));

        $lineItem = (new BookingLineItemFactory())->createProductLineItem($productId, $holdId, $holdToken, 2);

        static::assertSame(LineItem::PRODUCT_LINE_ITEM_TYPE, $lineItem->getType());
        static::assertSame($productId, $lineItem->getReferencedId());
        static::assertSame(2, $lineItem->getQuantity());
        static::assertFalse($lineItem->isStackable());
        static::assertTrue($lineItem->isRemovable());
        static::assertSame([
            'holdId' => $holdId,
            'holdToken' => $holdToken,
        ], $lineItem->getPayloadValue('fibBooking'));
    }
}
