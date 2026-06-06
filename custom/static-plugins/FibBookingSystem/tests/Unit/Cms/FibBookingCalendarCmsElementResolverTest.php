<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Cms;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use FibBookingSystem\Core\Content\Cms\FibBookingCalendarCmsElementResolver;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\FieldConfig;
use Shopware\Core\Content\Cms\DataResolver\FieldConfigCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class FibBookingCalendarCmsElementResolverTest extends TestCase
{
    public function testTypeMatchesElementName(): void
    {
        $resolver = new FibBookingCalendarCmsElementResolver(
            $this->createStub(EntityRepository::class),
            $this->createStub(EntityRepository::class),
        );

        static::assertSame('fib-booking-calendar', $resolver->getType());
    }

    public function testEnrichWithoutResourceYieldsEmptyData(): void
    {
        $resolver = new FibBookingCalendarCmsElementResolver(
            $this->createStub(EntityRepository::class),
            $this->createStub(EntityRepository::class),
        );
        $slot = $this->createSlot(null);

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertNull($data->get('resourceId'));
        static::assertSame([], $data->get('packages'));
    }

    public function testEnrichRejectsMalformedResourceId(): void
    {
        $resourceRepository = $this->createMock(EntityRepository::class);
        $resourceRepository->expects(static::never())->method('search');

        $resolver = new FibBookingCalendarCmsElementResolver(
            $resourceRepository,
            $this->createStub(EntityRepository::class),
        );
        $slot = $this->createSlot('<script>not-a-uuid</script>');

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertNull($data->get('resourceId'));
    }

    public function testEnrichLoadsResourceAndPackagesSortedByNumber(): void
    {
        $resourceId = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $resource = new BookingResourceEntity();
        $resource->setUniqueIdentifier($resourceId);
        $resource->setName('Demo Event Hall');
        $resource->setActive(true);

        $resourceRepository = $this->createStub(EntityRepository::class);
        $resourceRepository->method('search')->willReturn(
            $this->searchResult(new EntityCollection([$resource])),
        );

        $configRepository = $this->createStub(EntityRepository::class);
        $configRepository->method('search')->willReturn(
            $this->searchResult(new ProductBookingConfigCollection([
                $this->createConfig('p2', 'PKG-2', null),
                $this->createConfig('p1', 'PKG-1', 'Starter'),
            ])),
        );

        $resolver = new FibBookingCalendarCmsElementResolver($resourceRepository, $configRepository);
        $slot = $this->createSlot(strtoupper($resourceId));

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertSame($resourceId, $data->get('resourceId'));
        static::assertSame('Demo Event Hall', $data->get('resourceName'));

        $packages = $data->get('packages');
        static::assertCount(2, $packages);
        static::assertSame('PKG-1', $packages[0]['productNumber']);
        static::assertSame('Starter', $packages[0]['name']);
        static::assertSame('PKG-2', $packages[1]['productNumber']);
        static::assertNull($packages[1]['name']);
    }

    private function createConfig(string $productId, string $productNumber, ?string $name): ProductBookingConfigEntity
    {
        $product = new ProductEntity();
        $product->setUniqueIdentifier($productId);
        $product->setId($productId);
        $product->setProductNumber($productNumber);
        $product->setTranslated(['name' => $name]);

        $config = new ProductBookingConfigEntity();
        $config->setUniqueIdentifier(Uuid::randomHex());
        $config->setProduct($product);

        return $config;
    }

    /**
     * @param EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity> $collection
     */
    private function searchResult(EntityCollection $collection): EntitySearchResult
    {
        return new EntitySearchResult(
            'entity',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );
    }

    private function createSlot(?string $resourceId): CmsSlotEntity
    {
        $slot = new CmsSlotEntity();
        $slot->setUniqueIdentifier(Uuid::randomHex());

        $config = new FieldConfigCollection();
        if ($resourceId !== null) {
            $config->add(new FieldConfig('resourceId', FieldConfig::SOURCE_STATIC, $resourceId));
        }
        $slot->setFieldConfig($config);

        return $slot;
    }

    private function createResolverContext(): ResolverContext
    {
        $salesChannelContext = $this->createStub(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new ResolverContext($salesChannelContext, new Request());
    }
}
