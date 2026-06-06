<?php

declare(strict_types=1);

namespace FibBookingSystem;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain exceptions following the Shopware HttpException pattern:
 * one exception class per domain with stable error codes and static factories.
 */
class FibBookingException extends HttpException
{
    public const RESOURCE_NOT_FOUND = 'FIB_BOOKING__RESOURCE_NOT_FOUND';
    public const WINDOW_UNAVAILABLE = 'FIB_BOOKING__WINDOW_UNAVAILABLE';
    public const HOLD_LIMIT_REACHED = 'FIB_BOOKING__HOLD_LIMIT_REACHED';
    public const RESERVATION_NOT_FOUND = 'FIB_BOOKING__RESERVATION_NOT_FOUND';
    public const TICKET_ALREADY_EXISTS = 'FIB_BOOKING__TICKET_ALREADY_EXISTS';
    public const INVALID_PAYLOAD = 'FIB_BOOKING__INVALID_PAYLOAD';
    public const NO_TICKETS_FOR_ORDER = 'FIB_BOOKING__NO_TICKETS_FOR_ORDER';
    public const SCAN_CHECK_OUT_DISABLED = 'FIB_BOOKING__SCAN_CHECK_OUT_DISABLED';

    public static function resourceNotFound(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::RESOURCE_NOT_FOUND,
            'The requested booking resource does not exist.',
        );
    }

    public static function scanCheckOutDisabled(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::SCAN_CHECK_OUT_DISABLED,
            'Check-out scanning is disabled. Enable "scanCheckOutEnabled" in the plugin configuration first.',
        );
    }

    public static function holdLimitReached(int $limit): self
    {
        return new self(
            Response::HTTP_TOO_MANY_REQUESTS,
            self::HOLD_LIMIT_REACHED,
            'No more than {{ limit }} concurrent booking holds are allowed per customer. Complete or abandon an existing hold first.',
            ['limit' => $limit],
        );
    }

    public static function windowUnavailable(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::WINDOW_UNAVAILABLE,
            'The requested booking window is no longer available.',
        );
    }

    public static function reservationNotFound(string $reservationId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::RESERVATION_NOT_FOUND,
            'The requested booking reservation "{{ reservationId }}" does not exist.',
            ['reservationId' => $reservationId],
        );
    }

    public static function ticketAlreadyExists(string $reservationId): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::TICKET_ALREADY_EXISTS,
            'A valid ticket already exists for reservation "{{ reservationId }}".',
            ['reservationId' => $reservationId],
        );
    }

    public static function noTicketsForOrder(string $orderId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::NO_TICKETS_FOR_ORDER,
            'Order "{{ orderId }}" has no issued booking tickets to render.',
            ['orderId' => $orderId],
        );
    }

    public static function invalidPayload(string $field, string $reason): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_PAYLOAD,
            'Invalid booking payload for "{{ field }}": {{ reason }}',
            ['field' => $field, 'reason' => $reason],
        );
    }
}
