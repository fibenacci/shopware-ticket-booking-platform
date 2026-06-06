<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingTicket;

use FibBookingSystem\Core\Content\BookingReservation\BookingReservationDefinition;
use FibBookingSystem\Core\Domain\Validity\EntryPolicy;
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

    /**
     * DAL-level default: entry_policy is Required and validated BEFORE the
     * SQL column default could apply — DAL creates without an explicit value
     * must be filled here. (The regular issue path inserts raw and relies on
     * the column default, see BookingTicketService.).
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'entryPolicy' => EntryPolicy::SINGLE,
        ];
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
            new DateTimeField('valid_from', 'validFrom'),
            (new StringField('entry_policy', 'entryPolicy'))->addFlags(new Required()),
            new IntField('max_entries_per_day', 'maxEntriesPerDay'),
            new StringField('validity_anchor', 'validityAnchor'),
            new StringField('validity_duration', 'validityDuration'),
            new StringField('seat_label', 'seatLabel'),
            new FkField('replaced_ticket_id', 'replacedTicketId', self::class),
            new FkField('owner_customer_id', 'ownerCustomerId', CustomerDefinition::class),
            new JsonField('payload', 'payload'),
            new ManyToOneAssociationField('reservation', 'reservation_id', BookingReservationDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
