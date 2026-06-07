<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeat;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * One numbered seat of a seatmap resource (docs/SEATING_PLAN.md). The seat
 * set is slot-independent — the same room serves every showing; the per-slot
 * state lives in fib_booking_seat_claim.
 */
class BookingSeatDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_seat';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingSeatCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingSeatEntity::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'active' => true,
        ];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('resource_id', 'resourceId', BookingResourceDefinition::class))->addFlags(new Required()),
            (new StringField('row_label', 'rowLabel'))->addFlags(new Required()),
            (new StringField('seat_label', 'seatLabel'))->addFlags(new Required()),
            (new IntField('pos_x', 'posX'))->addFlags(new Required()),
            (new IntField('pos_y', 'posY'))->addFlags(new Required()),
            // Per-seat angle (deg) for curved/rotated rows — the curve maths
            // runs in the editor; persisted seats are flat points.
            new IntField('rotation', 'rotation'),
            new StringField('category', 'category'),
            (new BoolField('active', 'active'))->addFlags(new Required()),
            new ManyToOneAssociationField('resource', 'resource_id', BookingResourceDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
