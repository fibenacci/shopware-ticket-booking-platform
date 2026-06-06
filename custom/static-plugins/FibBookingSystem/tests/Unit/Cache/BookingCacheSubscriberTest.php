<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Cache;

use FibBookingSystem\Cache\BookingCacheSubscriber;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheStoreEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingCacheSubscriberTest extends TestCase
{
    public function testInvalidatesBookingConfigurationWhenResourceChanges(): void
    {
        $cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $cacheInvalidator->expects(static::once())
            ->method('invalidate')
            ->with([BookingCacheSubscriber::BOOKING_CONFIGURATION_TAG]);

        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $event->method('getEventByEntityName')->willReturnCallback(
            static fn (string $entityName) => $entityName === BookingResourceDefinition::ENTITY_NAME
                ? self::createStub(EntityWrittenEvent::class)
                : null
        );

        (new BookingCacheSubscriber($cacheInvalidator))->invalidateBookingConfiguration($event);
    }

    public function testInvalidatesProductAndBookingConfigurationWhenProductBookingConfigChanges(): void
    {
        $cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $cacheInvalidator->expects(static::once())
            ->method('invalidate')
            ->with(['product', BookingCacheSubscriber::BOOKING_CONFIGURATION_TAG]);

        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $event->method('getEventByEntityName')->willReturnCallback(
            static fn (string $entityName) => $entityName === ProductBookingConfigDefinition::ENTITY_NAME
                ? self::createStub(EntityWrittenEvent::class)
                : null
        );

        (new BookingCacheSubscriber($cacheInvalidator))->invalidateBookingConfiguration($event);
    }

    public function testAddsBookingConfigurationTagToProductDetailCacheEntry(): void
    {
        $event = new HttpCacheStoreEvent(
            $this->createStub(CacheItemInterface::class),
            [],
            new Request(attributes: ['_route' => 'frontend.detail.page']),
            new Response(),
        );

        (new BookingCacheSubscriber($this->createStub(CacheInvalidator::class)))->addBookingConfigurationTag($event);

        static::assertContains(BookingCacheSubscriber::BOOKING_CONFIGURATION_TAG, $event->tags);
    }
}
