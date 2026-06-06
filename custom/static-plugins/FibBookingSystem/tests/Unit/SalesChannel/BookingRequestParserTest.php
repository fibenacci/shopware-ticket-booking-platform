<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\SalesChannel;

use FibBookingSystem\Core\Content\Booking\SalesChannel\BookingRequestParser;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class BookingRequestParserTest extends TestCase
{
    public function testParsesValidPayload(): void
    {
        $request = new Request(content: json_encode([
            'resourceId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
            'startsAt' => '2026-07-01T18:00:00+00:00',
            'quantity' => 2,
        ], \JSON_THROW_ON_ERROR));

        $payload = BookingRequestParser::payload($request);

        static::assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', BookingRequestParser::uuid($payload, 'resourceId'));
        static::assertSame('2026-07-01T18:00:00+00:00', BookingRequestParser::dateTime($payload, 'startsAt')->format(\DATE_ATOM));
        static::assertSame(2, BookingRequestParser::positiveInt($payload, 'quantity'));
    }

    public function testEmptyBodyYieldsEmptyPayload(): void
    {
        static::assertSame([], BookingRequestParser::payload(new Request()));
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::payload(new Request(content: '{not json'));
    }

    public function testNonObjectJsonIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::payload(new Request(content: '"just a string"'));
    }

    public function testInvalidUuidIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessageMatches('/resourceId/');
        BookingRequestParser::uuid(['resourceId' => '<script>alert(1)</script>'], 'resourceId');
    }

    public function testMissingUuidIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::uuid([], 'resourceId');
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::dateTime(['startsAt' => 'not-a-date'], 'startsAt');
    }

    public function testStringQuantityIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::positiveInt(['quantity' => '2'], 'quantity');
    }

    public function testZeroQuantityIsRejected(): void
    {
        $this->expectException(FibBookingException::class);
        BookingRequestParser::positiveInt(['quantity' => 0], 'quantity');
    }
}
