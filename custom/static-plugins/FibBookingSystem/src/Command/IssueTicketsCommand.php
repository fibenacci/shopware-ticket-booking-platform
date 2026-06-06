<?php

declare(strict_types=1);

namespace FibBookingSystem\Command;

use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-issues missing tickets for confirmed reservations of an order — ops tool
 * for cases where ticket issuing failed after payment (mail/PDF outage etc.).
 */
#[AsCommand(
    name: 'fib-booking:tickets:issue',
    description: 'Issue missing tickets for the confirmed reservations of an order',
)]
class IssueTicketsCommand extends Command
{
    /**
     * @param EntityRepository<OrderCollection>              $orderRepository
     * @param EntityRepository<BookingReservationCollection> $reservationRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly BookingTicketService $ticketService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('orderNumber', InputArgument::REQUIRED, 'Order number (e.g. 10001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createCLIContext();
        $orderNumber = $input->getArgument('orderNumber');
        $orderNumber = is_string($orderNumber) ? $orderNumber : '';

        $orderCriteria = new Criteria();
        $orderCriteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $orderId = $this->orderRepository->searchIds($orderCriteria, $context)->firstId();

        if ($orderId === null) {
            $io->error(sprintf('Order "%s" not found.', $orderNumber));

            return Command::FAILURE;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('status', 'confirmed'));

        $reservations = $this->reservationRepository->search($criteria, $context)->getEntities();

        if ($reservations->count() === 0) {
            $io->warning('No confirmed reservations for this order.');

            return Command::SUCCESS;
        }

        $failures = 0;

        /** @var BookingReservationEntity $reservation */
        foreach ($reservations as $reservation) {
            try {
                $ticket = $this->ticketService->issueTicket($reservation->getId(), $context);
                $io->success(sprintf('%s → ticket %s issued.', $reservation->getBookingNumber(), $ticket->getTicketNumber()));
            } catch (FibBookingException $exception) {
                if ($exception->getErrorCode() === FibBookingException::TICKET_ALREADY_EXISTS) {
                    $io->note(sprintf('%s → ticket already exists, skipped.', $reservation->getBookingNumber()));

                    continue;
                }

                ++$failures;
                $io->error(sprintf('%s → %s: %s', $reservation->getBookingNumber(), $exception->getErrorCode(), $exception->getMessage()));
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
