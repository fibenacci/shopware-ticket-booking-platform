<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingReservation;

use FibBookingSystem\Core\Content\BookingHold\BookingHoldDefinition;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
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
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class BookingReservationDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_reservation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingReservationCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingReservationEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('resource_id', 'resourceId', BookingResourceDefinition::class))->addFlags(new Required()),
            new FkField('order_id', 'orderId', OrderDefinition::class),
            new ReferenceVersionField(OrderDefinition::class, 'order_version_id'),
            new FkField('order_line_item_id', 'orderLineItemId', OrderLineItemDefinition::class),
            new ReferenceVersionField(OrderLineItemDefinition::class, 'order_line_item_version_id'),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new FkField('hold_id', 'holdId', BookingHoldDefinition::class),
            (new StringField('booking_number', 'bookingNumber'))->addFlags(new Required()),
            (new DateTimeField('starts_at', 'startsAt'))->addFlags(new Required()),
            (new DateTimeField('ends_at', 'endsAt'))->addFlags(new Required()),
            (new IntField('quantity', 'quantity'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new JsonField('payload', 'payload'),
            new ManyToOneAssociationField('resource', 'resource_id', BookingResourceDefinition::class, 'id'),
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id'),
            new ManyToOneAssociationField('orderLineItem', 'order_line_item_id', OrderLineItemDefinition::class, 'id'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id'),
            new ManyToOneAssociationField('hold', 'hold_id', BookingHoldDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
