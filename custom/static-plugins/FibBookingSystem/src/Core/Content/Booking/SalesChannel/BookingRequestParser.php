<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use FibBookingSystem\FibBookingException;
use JsonException;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Strict request parsing shared by the booking Store API routes. Every field
 * is validated before it reaches a service; failures raise
 * FibBookingException::invalidPayload with a stable error code.
 */
final class BookingRequestParser
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FibBookingException::invalidPayload('body', 'malformed JSON');
        }

        if (!is_array($decoded)) {
            throw FibBookingException::invalidPayload('body', 'expected a JSON object');
        }

        // JSON objects decode to string-keyed arrays.
        /** @var array<string, mixed> $payload */
        $payload = $decoded;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function uuid(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        $normalized = is_string($value) ? strtolower($value) : null;

        if ($normalized === null || !Uuid::isValid($normalized)) {
            throw FibBookingException::invalidPayload($field, 'expected a UUID');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function dateTime(array $payload, string $field): DateTimeInterface
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw FibBookingException::invalidPayload($field, 'expected an ISO 8601 date');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw FibBookingException::invalidPayload($field, 'expected an ISO 8601 date');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function positiveInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value) || $value < 1) {
            throw FibBookingException::invalidPayload($field, 'expected a positive integer');
        }

        return $value;
    }
}
