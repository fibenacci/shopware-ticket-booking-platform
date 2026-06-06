<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingTicket;

use FibBookingSystem\Core\Content\BookingReservation\BookingReservationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class BookingTicketDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_ticket';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingTicketCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingTicketEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('reservation_id', 'reservationId', BookingReservationDefinition::class))->addFlags(new Required()),
            (new StringField('ticket_number', 'ticketNumber'))->addFlags(new Required()),
            (new StringField('scan_token_hash', 'scanTokenHash'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            (new DateTimeField('issued_at', 'issuedAt'))->addFlags(new Required()),
            new DateTimeField('sent_at', 'sentAt'),
            new DateTimeField('scanned_at', 'scannedAt'),
            new DateTimeField('expires_at', 'expiresAt'),
            new JsonField('payload', 'payload'),
            new ManyToOneAssociationField('reservation', 'reservation_id', BookingReservationDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
