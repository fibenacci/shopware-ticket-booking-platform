<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingHold;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
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
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

class BookingHoldDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_hold';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingHoldCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingHoldEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('resource_id', 'resourceId', BookingResourceDefinition::class))->addFlags(new Required()),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            (new StringField('token', 'token'))->addFlags(new Required()),
            (new DateTimeField('starts_at', 'startsAt'))->addFlags(new Required()),
            (new DateTimeField('ends_at', 'endsAt'))->addFlags(new Required()),
            (new DateTimeField('expires_at', 'expiresAt'))->addFlags(new Required()),
            (new IntField('quantity', 'quantity'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new JsonField('payload', 'payload'),
            new ManyToOneAssociationField('resource', 'resource_id', BookingResourceDefinition::class, 'id'),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
