<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart\Resale;

use FibBookingSystem\Core\Domain\Resale\ListingCartReader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Prices and validates `fib-resale` line items on EVERY cart calculation —
 * the eligibility rules re-apply right up to order placement:
 *
 * - the listing must still be active and fixed-price,
 * - the buyer must be a logged-in, non-guest customer (private C2C — see
 *   the legal framing in docs/RESELL_PLAN.md),
 * - buyer ≠ seller (no buying your own lot),
 * - the price is ALWAYS the listing's ask price, read server-side. Tax-free
 *   on purpose: a private seller charges no VAT, and the platform only
 *   intermediates (zero fee) — the order merely collects on the seller's
 *   behalf.
 *
 * Failing items are dropped from the cart with a translatable error instead
 * of blocking the whole checkout — same UX as a product that went offline.
 *
 * @phpstan-import-type CartRow from ListingCartReader
 */
class ResaleCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private const DATA_KEY_PREFIX = 'fib-resale-listing-';

    public function __construct(
        private readonly ListingCartReader $listingReader,
        private readonly QuantityPriceCalculator $priceCalculator,
    ) {
    }

    public function collect(
        CartDataCollection $data,
        Cart $original,
        SalesChannelContext $context,
        CartBehavior $behavior,
    ): void {
        $listingIds = [];
        foreach ($original->getLineItems()->filterType(ResaleLineItemFactory::TYPE) as $lineItem) {
            if (is_string($lineItem->getReferencedId())) {
                $listingIds[] = $lineItem->getReferencedId();
            }
        }

        if ($listingIds === []) {
            return;
        }

        foreach ($this->listingReader->fetchCartRows($listingIds) as $listingId => $row) {
            $data->set(self::DATA_KEY_PREFIX . $listingId, $row);
        }
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior,
    ): void {
        $lineItems = $original->getLineItems()->filterType(ResaleLineItemFactory::TYPE);

        if (\count($lineItems) === 0) {
            return;
        }

        $customer = $context->getCustomer();
        $buyerId = $customer !== null && !$customer->getGuest() ? $customer->getId() : null;

        foreach ($lineItems as $lineItem) {
            /** @var CartRow|null $row */
            $row = $data->get(self::DATA_KEY_PREFIX . $lineItem->getReferencedId());

            $rejection = $this->rejectionReason($row, $buyerId);

            if ($rejection !== null || $row === null) {
                $toCalculate->addErrors(new GenericCartError(
                    sprintf('fib-resale-%s-%s', $rejection ?? 'gone', $lineItem->getId()),
                    sprintf('fib-booking.resale-%s', $rejection ?? 'gone'),
                    ['lineItemId' => $lineItem->getId()],
                    Error::LEVEL_ERROR,
                    false,
                    true,
                    true,
                ));

                continue; // not added to $toCalculate — the item drops out
            }

            $this->priceAndLabel($lineItem, $row, $context);
            $toCalculate->add($lineItem);
        }
    }

    /**
     * @param CartRow|null $row
     */
    private function rejectionReason(
        ?array $row,
        ?string $buyerId,
    ): ?string {
        if ($row === null || $row['status'] !== 'active' || $row['mode'] !== 'fixed_price') {
            return 'gone';
        }

        if ($buyerId === null) {
            return 'login-required';
        }

        if ($row['seller_customer_id'] === strtolower($buyerId)) {
            return 'own-listing';
        }

        return null;
    }

    /**
     * @param CartRow $row
     */
    private function priceAndLabel(
        LineItem $lineItem,
        array $row,
        SalesChannelContext $context,
    ): void {
        // Tax-free by design (private C2C sale, platform without profit).
        $definition = new QuantityPriceDefinition($row['ask_price'], new TaxRuleCollection(), 1);

        // Quantity is structurally 1 (factory sets it, non-stackable) —
        // setQuantity() on a non-stackable item throws, so don't touch it.
        $lineItem->setPriceDefinition($definition);
        $lineItem->setPrice($this->priceCalculator->calculate($definition, $context));
        $lineItem->setLabel(sprintf(
            'Resale ticket %s — %s%s',
            $row['ticket_number'],
            $row['resource_name'],
            $row['seat_label'] !== null ? sprintf(' (seat %s)', $row['seat_label']) : '',
        ));
        // Persisted onto order_line_item.payload — the settlement subscriber
        // resolves the listing from here after payment.
        $lineItem->setPayloadValue('fibResale', [
            'listingId' => $row['listing_id'],
            'ticketId' => $row['ticket_id'],
        ], true);
    }
}
