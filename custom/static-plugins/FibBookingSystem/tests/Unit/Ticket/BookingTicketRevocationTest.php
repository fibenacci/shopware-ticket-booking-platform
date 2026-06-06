<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Ticket;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Revocation is the cancellation/refund tail: every still-usable ticket of
 * the given reservations flips to `revoked` in ONE bulk write; final states
 * are untouched and an empty input never hits the repository.
 */
class BookingTicketRevocationTest extends TestCase
{
    public function testRevokesEveryUsableTicketInOneBulkWrite(): void
    {
        $ticketIds = ['f1b000000000000000000000000000a1', 'f1b000000000000000000000000000a2'];
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection> $ticketRepository */
        $ticketRepository = new StaticEntityRepository([$ticketIds]);

        $revoked = $this->service($ticketRepository)->revokeForReservations(
            ['f1b000000000000000000000000000r1'],
            Context::createDefaultContext(),
        );

        static::assertSame(2, $revoked);
        static::assertCount(1, $ticketRepository->updates, 'one bulk payload, not one write per ticket');
        static::assertSame(
            [
                ['id' => $ticketIds[0], 'status' => 'revoked'],
                ['id' => $ticketIds[1], 'status' => 'revoked'],
            ],
            $ticketRepository->updates[0],
        );
    }

    public function testNoUsableTicketsMeansNoWrite(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection> $ticketRepository */
        $ticketRepository = new StaticEntityRepository([[]]);

        $revoked = $this->service($ticketRepository)->revokeForReservations(
            ['f1b000000000000000000000000000r1'],
            Context::createDefaultContext(),
        );

        static::assertSame(0, $revoked);
        static::assertSame([], $ticketRepository->updates);
    }

    public function testEmptyReservationListNeverTouchesTheRepository(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection> $ticketRepository */
        $ticketRepository = new StaticEntityRepository([]);

        $revoked = $this->service($ticketRepository)->revokeForReservations([], Context::createDefaultContext());

        static::assertSame(0, $revoked);
        static::assertSame([], $ticketRepository->updates);
    }

    /**
     * @param StaticEntityRepository<\FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection> $ticketRepository
     */
    private function service(StaticEntityRepository $ticketRepository): BookingTicketService
    {
        return new BookingTicketService(
            $this->createStub(Connection::class),
            $ticketRepository,
            $this->createStub(EventDispatcherInterface::class),
            new QrCodeGenerator(),
            $this->createStub(NumberRangeValueGeneratorInterface::class),
            new TokenCipher('test-secret'),
            new TicketValidityResolver(),
            new StaticSystemConfigService(),
        );
    }
}
