<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Subscriber;

use FibBookingSystem\Extension\Content\Product\ProductBookingConfigExtension;
use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductBookingConfigCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageCriteriaEvent::class => 'addBookingConfigAssociation',
        ];
    }

    public function addBookingConfigAssociation(ProductPageCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation(ProductBookingConfigExtension::EXTENSION_NAME);
    }
}
