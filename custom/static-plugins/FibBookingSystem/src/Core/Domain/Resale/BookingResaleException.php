<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use FibBookingSystem\FibBookingException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resale-domain exceptions — extends the plugin base so existing
 * catch(FibBookingException) sites keep working (one exception class per
 * domain, see the base class docblock).
 */
class BookingResaleException extends FibBookingException
{
    public const TICKET_NOT_LISTABLE = 'FIB_BOOKING__TICKET_NOT_LISTABLE';
    public const LISTING_ALREADY_EXISTS = 'FIB_BOOKING__LISTING_ALREADY_EXISTS';
    public const LISTING_NOT_FOUND = 'FIB_BOOKING__LISTING_NOT_FOUND';
    public const NOT_LISTING_OWNER = 'FIB_BOOKING__NOT_LISTING_OWNER';
    public const TICKET_NOT_TRANSFERABLE = 'FIB_BOOKING__TICKET_NOT_TRANSFERABLE';

    public static function ticketNotListable(string $reason): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::TICKET_NOT_LISTABLE,
            'This ticket cannot be listed for resale: {{ reason }}',
            ['reason' => $reason],
        );
    }

    public static function listingAlreadyExists(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::LISTING_ALREADY_EXISTS,
            'A live listing for this ticket already exists.',
        );
    }

    public static function listingNotFound(): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::LISTING_NOT_FOUND,
            'The requested listing does not exist.',
        );
    }

    public static function notListingOwner(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::NOT_LISTING_OWNER,
            'Only the seller may modify this listing.',
        );
    }

    public static function ticketNotTransferable(string $reason): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::TICKET_NOT_TRANSFERABLE,
            'This ticket cannot be transferred: {{ reason }}',
            ['reason' => $reason],
        );
    }
}
