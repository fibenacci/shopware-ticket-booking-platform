<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingScanLog;

use FibBookingSystem\Core\Content\BookingTicket\BookingTicketDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Audit trail of every scan attempt. Append-only: rows are written once and
 * never updated (no UpdatedAtField on purpose — the table has no updated_at).
 */
class BookingScanLogDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_scan_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingScanLogCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingScanLogEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new FkField('ticket_id', 'ticketId', BookingTicketDefinition::class),
            (new StringField('verdict', 'verdict'))->addFlags(new Required()),
            (new StringField('direction', 'direction'))->addFlags(new Required()),
            new StringField('token_fingerprint', 'tokenFingerprint'),
            new StringField('scanned_by', 'scannedBy'),
            (new StringField('source', 'source'))->addFlags(new Required()),
            new ManyToOneAssociationField('ticket', 'ticket_id', BookingTicketDefinition::class, 'id'),
            new CreatedAtField(),
        ]);
    }
}
