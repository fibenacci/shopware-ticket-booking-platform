<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeatClaim;

use FibBookingSystem\Core\Content\BookingHold\BookingHoldDefinition;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationDefinition;
use FibBookingSystem\Core\Content\BookingSeat\BookingSeatDefinition;
use FibBookingSystem\Core\Content\BookingSlot\BookingSlotDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Seat ownership for ONE slot (docs/SEATING_PLAN.md). Created exclusively by
 * SeatClaimService inside the hold transaction — the UNIQUE(seat_id, slot_id)
 * key is the race decision, so writes never go through the Admin API.
 * Append/flip/delete only; no UpdatedAtField on purpose.
 */
class BookingSeatClaimDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_seat_claim';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingSeatClaimCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingSeatClaimEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('seat_id', 'seatId', BookingSeatDefinition::class))->addFlags(new Required()),
            (new FkField('slot_id', 'slotId', BookingSlotDefinition::class))->addFlags(new Required()),
            new FkField('hold_id', 'holdId', BookingHoldDefinition::class),
            new FkField('reservation_id', 'reservationId', BookingReservationDefinition::class),
            new ManyToOneAssociationField('seat', 'seat_id', BookingSeatDefinition::class, 'id'),
            new ManyToOneAssociationField('slot', 'slot_id', BookingSlotDefinition::class, 'id'),
            new ManyToOneAssociationField('hold', 'hold_id', BookingHoldDefinition::class, 'id'),
            new ManyToOneAssociationField('reservation', 'reservation_id', BookingReservationDefinition::class, 'id'),
            new CreatedAtField(),
        ]);
    }
}
