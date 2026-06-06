<?php

declare(strict_types=1);

namespace FibBookingSystem\Cache;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigDefinition;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheStoreEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingCacheSubscriber implements EventSubscriberInterface
{
    public const BOOKING_CONFIGURATION_TAG = 'fib-booking-configuration';

    public function __construct(private readonly CacheInvalidator $cacheInvalidator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => 'invalidateBookingConfiguration',
            HttpCacheStoreEvent::class => 'addBookingConfigurationTag',
        ];
    }

    public function addBookingConfigurationTag(HttpCacheStoreEvent $event): void
    {
        if ($event->request->attributes->get('_route') !== 'frontend.detail.page') {
            return;
        }

        $event->tags[] = self::BOOKING_CONFIGURATION_TAG;
    }

    public function invalidateBookingConfiguration(EntityWrittenContainerEvent $event): void
    {
        $tags = [];

        if ($event->getEventByEntityName(BookingResourceDefinition::ENTITY_NAME) !== null) {
            $tags[] = self::BOOKING_CONFIGURATION_TAG;
        }

        if ($event->getEventByEntityName(ProductBookingConfigDefinition::ENTITY_NAME) !== null) {
            $tags[] = 'product';
            $tags[] = self::BOOKING_CONFIGURATION_TAG;
        }

        if ($tags === []) {
            return;
        }

        $this->cacheInvalidator->invalidate($tags);
    }
}
