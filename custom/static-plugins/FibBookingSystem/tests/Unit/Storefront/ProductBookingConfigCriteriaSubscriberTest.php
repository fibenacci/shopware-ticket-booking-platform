<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Storefront;

use FibBookingSystem\Extension\Content\Product\ProductBookingConfigExtension;
use FibBookingSystem\Storefront\Subscriber\ProductBookingConfigCriteriaSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;

class ProductBookingConfigCriteriaSubscriberTest extends TestCase
{
    public function testAddsBookingConfigAssociationToProductPageCriteria(): void
    {
        $criteria = new Criteria();
        $event = new ProductPageCriteriaEvent(
            Uuid::randomHex(),
            $criteria,
            $this->createStub(SalesChannelContext::class),
        );

        (new ProductBookingConfigCriteriaSubscriber())->addBookingConfigAssociation($event);

        static::assertTrue($criteria->hasAssociation(ProductBookingConfigExtension::EXTENSION_NAME));
    }
}
