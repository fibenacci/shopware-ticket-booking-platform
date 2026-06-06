<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart\Resale;

use FibBookingSystem\FibBookingException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Lets the stock cart routes (`/checkout/cart/line-item`, store-api and
 * storefront alike) accept `fib-resale` items: referencedId = the listing id.
 *
 * Deliberately NO price permission check à la CustomLineItemFactory — the
 * client never supplies a price. ResaleCartProcessor prices the item from
 * the listing row on every cart calculation (server-side truth), and all
 * eligibility rules (active, fixed price, buyer ≠ seller, no guests) live
 * there too, so they re-apply right up to order placement.
 */
class ResaleLineItemFactory implements LineItemFactoryInterface
{
    public const TYPE = 'fib-resale';

    public function supports(string $type): bool
    {
        return $type === self::TYPE;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(
        array $data,
        SalesChannelContext $context,
    ): LineItem {
        $listingId = $data['referencedId'] ?? null;

        if (!is_string($listingId) || !Uuid::isValid($listingId)) {
            throw FibBookingException::invalidPayload('referencedId', 'must be a listing id');
        }

        $id = is_string($data['id'] ?? null) && Uuid::isValid($data['id']) ? $data['id'] : Uuid::randomHex();

        // One identified ticket per lot — quantity is structurally 1.
        $lineItem = new LineItem($id, self::TYPE, $listingId, 1);
        $lineItem->setStackable(false);
        $lineItem->setRemovable(true);
        $lineItem->markModified();

        return $lineItem;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(
        LineItem $lineItem,
        array $data,
        SalesChannelContext $context,
    ): void {
        throw FibBookingException::invalidPayload('lineItem', 'resale line items cannot be modified');
    }
}
