<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\ProductBookingConfig;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductBookingConfigDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_product_config';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ProductBookingConfigCollection::class;
    }

    public function getEntityClass(): string
    {
        return ProductBookingConfigEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class, 'product_version_id'))->addFlags(new Required()),
            (new FkField('resource_id', 'resourceId', BookingResourceDefinition::class))->addFlags(new Required()),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required()),
            (new IntField('slot_minutes', 'slotMinutes'))->addFlags(new Required()),
            (new StringField('validity_mode', 'validityMode'))->addFlags(new Required()),
            new StringField('validity_duration', 'validityDuration'),
            new StringField('validity_anchor', 'validityAnchor'),
            (new StringField('entry_policy', 'entryPolicy'))->addFlags(new Required()),
            new IntField('max_entries_per_day', 'maxEntriesPerDay'),
            new OneToOneAssociationField('product', 'product_id', 'id', ProductDefinition::class, false),
            new ManyToOneAssociationField('resource', 'resource_id', BookingResourceDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
