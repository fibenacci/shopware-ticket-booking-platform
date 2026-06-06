<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingListing;

use FibBookingSystem\Core\Content\BookingTicket\BookingTicketDefinition;
use FibBookingSystem\Core\Domain\Resale\ListingStatus;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Resale listing — one lot per listing (docs/RESELL_PLAN.md). NOTE:
 * `active_ticket_id` (the unique insert-wins guard) is intentionally NOT
 * part of this definition: it is an internal concurrency column written
 * exclusively by BookingResaleService, like scan_token_cipher on tickets.
 */
class BookingListingDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_listing';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingListingCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingListingEntity::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'status' => ListingStatus::ACTIVE,
        ];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('ticket_id', 'ticketId', BookingTicketDefinition::class))->addFlags(new Required()),
            (new FkField('seller_customer_id', 'sellerCustomerId', CustomerDefinition::class))->addFlags(new Required()),
            (new StringField('mode', 'mode'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            (new FloatField('ask_price', 'askPrice'))->addFlags(new Required()),
            new FloatField('reserve_price', 'reservePrice'),
            new FloatField('min_increment', 'minIncrement'),
            new DateTimeField('ends_at', 'endsAt'),
            new FkField('sold_to_customer_id', 'soldToCustomerId', CustomerDefinition::class),
            new FloatField('sold_price', 'soldPrice'),
            new DateTimeField('sold_at', 'soldAt'),
            new ManyToOneAssociationField('ticket', 'ticket_id', BookingTicketDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
