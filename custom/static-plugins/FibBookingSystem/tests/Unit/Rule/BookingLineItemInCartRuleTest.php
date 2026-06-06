<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Rule;

use FibBookingSystem\Rule\BookingLineItemInCartRule;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BookingLineItemInCartRuleTest extends TestCase
{
    public function testMatchesCartWithBookingLineItem(): void
    {
        $cart = new Cart('test');
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex());
        $lineItem->setPayloadValue('fibBooking', [
            'holdId' => Uuid::randomHex(),
            'holdToken' => bin2hex(random_bytes(16)),
        ]);
        $cart->add($lineItem);

        $scope = new CartRuleScope($cart, $this->createStub(SalesChannelContext::class));

        static::assertTrue((new BookingLineItemInCartRule())->match($scope));
    }

    public function testDoesNotMatchCartWithoutBookingLineItem(): void
    {
        $cart = new Cart('test');
        $cart->add(new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex()));

        $scope = new CartRuleScope($cart, $this->createStub(SalesChannelContext::class));

        static::assertFalse((new BookingLineItemInCartRule())->match($scope));
    }
}
