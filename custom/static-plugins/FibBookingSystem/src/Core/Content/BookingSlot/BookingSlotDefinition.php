<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSlot;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class BookingSlotDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_slot';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingSlotCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingSlotEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('resource_id', 'resourceId', BookingResourceDefinition::class))->addFlags(new Required()),
            (new DateTimeField('starts_at', 'startsAt'))->addFlags(new Required()),
            (new DateTimeField('ends_at', 'endsAt'))->addFlags(new Required()),
            (new IntField('capacity', 'capacity'))->addFlags(new Required()),
            (new BoolField('active', 'active'))->addFlags(new Required()),
            new ManyToOneAssociationField('resource', 'resource_id', BookingResourceDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
