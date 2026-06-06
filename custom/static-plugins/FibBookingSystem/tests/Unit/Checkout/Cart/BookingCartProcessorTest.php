<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Checkout\Cart;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Checkout\Cart\BookingCartProcessor;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BookingCartProcessorTest extends TestCase
{
    public function testBlocksCartWhenHoldIsInvalid(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $cart = new Cart('test-token');
        $cart->add($this->createBookingLineItem());

        $processor = new BookingCartProcessor($connection);
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createStub(SalesChannelContext::class),
            new CartBehavior(),
        );

        static::assertCount(1, $cart->getErrors());
        static::assertTrue($cart->getErrors()->first()?->blockOrder());
    }

    public function testDoesNotBlockCartWhenHoldIsValid(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $cart = new Cart('test-token');
        $cart->add($this->createBookingLineItem());

        $processor = new BookingCartProcessor($connection);
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createStub(SalesChannelContext::class),
            new CartBehavior(),
        );

        static::assertCount(0, $cart->getErrors());
    }

    private function createBookingLineItem(): LineItem
    {
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex());
        $lineItem->setPayloadValue('fibBooking', [
            'holdId' => Uuid::randomHex(),
            'holdToken' => bin2hex(random_bytes(16)),
        ]);

        return $lineItem;
    }
}
