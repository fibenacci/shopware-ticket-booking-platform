<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Cms;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\Cms\FibBookingCalendarCmsElementResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\FieldConfig;
use Shopware\Core\Content\Cms\DataResolver\FieldConfigCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class FibBookingCalendarCmsElementResolverTest extends TestCase
{
    public function testTypeMatchesElementName(): void
    {
        $resolver = new FibBookingCalendarCmsElementResolver($this->createStub(Connection::class));

        static::assertSame('fib-booking-calendar', $resolver->getType());
    }

    public function testEnrichWithoutResourceYieldsEmptyData(): void
    {
        $resolver = new FibBookingCalendarCmsElementResolver($this->createStub(Connection::class));
        $slot = $this->createSlot(null);

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertNull($data->get('resourceId'));
        static::assertSame([], $data->get('packages'));
    }

    public function testEnrichRejectsMalformedResourceId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('fetchOne');

        $resolver = new FibBookingCalendarCmsElementResolver($connection);
        $slot = $this->createSlot('<script>not-a-uuid</script>');

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertNull($data->get('resourceId'));
    }

    public function testEnrichLoadsResourceAndDeduplicatedPackages(): void
    {
        $resourceId = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('Demo Event Hall');
        $connection->method('fetchAllAssociative')->willReturn([
            ['product_id' => 'p1', 'product_number' => 'PKG-1', 'name' => 'Starter'],
            ['product_id' => 'p1', 'product_number' => 'PKG-1', 'name' => 'Starter (duplicate translation row)'],
            ['product_id' => 'p2', 'product_number' => 'PKG-2', 'name' => null],
        ]);

        $resolver = new FibBookingCalendarCmsElementResolver($connection);
        $slot = $this->createSlot(strtoupper($resourceId));

        $resolver->enrich($slot, $this->createResolverContext(), new ElementDataCollection());

        $data = $slot->getData();
        static::assertInstanceOf(ArrayStruct::class, $data);
        static::assertSame($resourceId, $data->get('resourceId'));
        static::assertSame('Demo Event Hall', $data->get('resourceName'));

        $packages = $data->get('packages');
        static::assertCount(2, $packages, 'duplicate product rows must be deduplicated');
        static::assertSame('Starter', $packages[0]['name']);
        static::assertSame('PKG-2', $packages[1]['productNumber']);
        static::assertNull($packages[1]['name']);
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
        $salesChannelContext->method('getLanguageId')->willReturn('2fbb5fe2e29a4d70aa5854ce7ce3e20b');

        return new ResolverContext($salesChannelContext, new Request());
    }
}
