<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Checkout\Cart;

use FibBookingSystem\Checkout\Cart\BookingCartProcessor;
use FibBookingSystem\Checkout\Cart\BookingPassStartDateSubscriber;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BookingCartProcessorTest extends TestCase
{
    public function testBlocksCartWhenHoldIsInvalid(): void
    {
        $cart = new Cart('test-token');
        $cart->add($this->createBookingLineItem());

        $processor = new BookingCartProcessor($this->createHoldRepository([]), $this->createConfigRepository([]));
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createSalesChannelContext(),
            new CartBehavior(),
        );

        static::assertCount(1, $cart->getErrors());
        static::assertTrue($cart->getErrors()->first()?->blockOrder());
    }

    public function testDoesNotBlockCartWhenHoldIsValid(): void
    {
        $cart = new Cart('test-token');
        $cart->add($this->createBookingLineItem());

        $processor = new BookingCartProcessor($this->createHoldRepository([Uuid::randomHex()]), $this->createConfigRepository([]));
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createSalesChannelContext(),
            new CartBehavior(),
        );

        static::assertCount(0, $cart->getErrors());
    }

    public function testBlocksCustomerAnchoredPassWithoutStartDate(): void
    {
        $productId = Uuid::randomHex();
        $cart = new Cart('test-token');
        $cart->add(new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId));

        $processor = new BookingCartProcessor(
            $this->createHoldRepository([]),
            $this->createConfigRepository([$productId]),
        );
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createSalesChannelContext(),
            new CartBehavior(),
        );

        static::assertCount(1, $cart->getErrors());
        static::assertTrue($cart->getErrors()->first()?->blockOrder());
    }

    public function testAcceptsCustomerAnchoredPassWithFutureStartDate(): void
    {
        $productId = Uuid::randomHex();
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);
        $lineItem->setPayloadValue(BookingPassStartDateSubscriber::PAYLOAD_KEY, '2099-06-15');

        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $processor = new BookingCartProcessor(
            $this->createHoldRepository([]),
            $this->createConfigRepository([$productId]),
        );
        $processor->process(
            new CartDataCollection(),
            new Cart('original-token'),
            $cart,
            $this->createSalesChannelContext(),
            new CartBehavior(),
        );

        static::assertCount(0, $cart->getErrors());
    }

    /**
     * @param list<string> $foundIds
     *
     * @return EntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection>
     */
    private function createHoldRepository(array $foundIds): EntityRepository
    {
        $result = new IdSearchResult(
            count($foundIds),
            array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $foundIds),
            new Criteria(),
            Context::createDefaultContext(),
        );

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('searchIds')->willReturn($result);

        return $repository;
    }

    /**
     * Config repository whose search() yields one customer-anchored period
     * config per given product id.
     *
     * @param list<string> $customerAnchoredProductIds
     *
     * @return EntityRepository<ProductBookingConfigCollection>
     */
    private function createConfigRepository(array $customerAnchoredProductIds): EntityRepository
    {
        $collection = new ProductBookingConfigCollection();
        foreach ($customerAnchoredProductIds as $productId) {
            $config = new ProductBookingConfigEntity();
            $config->setId(Uuid::randomHex());
            $config->setProductId($productId);
            $config->setValidityMode('period');
            $config->setValidityAnchor('customer');
            $config->setEnabled(true);
            $collection->add($config);
        }

        $result = new EntitySearchResult(
            'fib_booking_product_config',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
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
