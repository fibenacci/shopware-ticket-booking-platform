<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\NumberRange;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Migration\Migration1717000000CreateBookingTables;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BookingNumberRangeTest extends TestCase
{
    use KernelTestBehaviour;

    public function testBookingAndTicketNumberRangesGenerateValues(): void
    {
        (new Migration1717000000CreateBookingTables())->update(self::container()->get('Doctrine\DBAL\Connection'));

        $generator = self::container()->get(NumberRangeValueGeneratorInterface::class);

        $bookingNumber = $generator->getValue(BookingReservationService::NUMBER_RANGE_TYPE, Context::createDefaultContext(), null);
        $ticketNumber = $generator->getValue(BookingTicketService::NUMBER_RANGE_TYPE, Context::createDefaultContext(), null);

        static::assertMatchesRegularExpression('/^B\\d+$/', $bookingNumber);
        static::assertMatchesRegularExpression('/^T\\d+$/', $ticketNumber);
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
