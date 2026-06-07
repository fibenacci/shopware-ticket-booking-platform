<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingResource;

use FibBookingSystem\Core\Domain\Seating\SeatingMode;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class BookingResourceDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_resource';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingResourceCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingResourceEntity::class;
    }

    /**
     * DAL-level default: Required fields validate BEFORE SQL column defaults
     * could apply — creates without an explicit mode stay pool-based.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'seatingMode' => SeatingMode::POOL,
        ];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('product_id', 'productId', ProductDefinition::class),
            (new StringField('name', 'name'))->addFlags(new Required()),
            (new StringField('technical_name', 'technicalName'))->addFlags(new Required()),
            (new IntField('capacity', 'capacity'))->addFlags(new Required()),
            (new StringField('seating_mode', 'seatingMode'))->addFlags(new Required()),
            (new BoolField('active', 'active'))->addFlags(new Required()),
            new JsonField('configuration', 'configuration'),
            new JsonField('layout', 'layout'),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
