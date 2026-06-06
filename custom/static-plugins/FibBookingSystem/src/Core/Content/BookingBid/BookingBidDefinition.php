<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingBid;

use FibBookingSystem\Core\Content\BookingListing\BookingListingDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Auction bid — append-only audit, like the scan log: rows are written once
 * and never updated (no UpdatedAtField on purpose).
 */
class BookingBidDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'fib_booking_bid';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return BookingBidCollection::class;
    }

    public function getEntityClass(): string
    {
        return BookingBidEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('listing_id', 'listingId', BookingListingDefinition::class))->addFlags(new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new FloatField('amount', 'amount'))->addFlags(new Required()),
            new ManyToOneAssociationField('listing', 'listing_id', BookingListingDefinition::class, 'id'),
            new CreatedAtField(),
        ]);
    }
}
