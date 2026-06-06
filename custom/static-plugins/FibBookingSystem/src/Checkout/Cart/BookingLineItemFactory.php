<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;

class BookingLineItemFactory
{
    public function createProductLineItem(string $productId, string $holdId, string $holdToken, int $quantity = 1): LineItem
    {
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId, $quantity);
        $lineItem->setPayloadValue('fibBooking', [
            'holdId' => $holdId,
            'holdToken' => $holdToken,
        ], true);
        $lineItem->setStackable(false);
        $lineItem->setRemovable(true);

        return $lineItem;
    }
}
