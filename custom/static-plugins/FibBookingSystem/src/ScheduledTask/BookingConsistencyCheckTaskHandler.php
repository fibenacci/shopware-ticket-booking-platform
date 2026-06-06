<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use FibBookingSystem\Core\Domain\Availability\BookingConsistencyCheckService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Periodic no-overbooking audit. The expected result is silence — every
 * violation is an ERROR log (route it to alerting): the transactional
 * booking paths should make oversell impossible, so a hit means a real bug,
 * a manual DB edit or a broken migration that needs a human TODAY.
 */
#[AsMessageHandler(handles: BookingConsistencyCheckTask::class)]
class BookingConsistencyCheckTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly BookingConsistencyCheckService $consistencyCheckService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        foreach ($this->consistencyCheckService->findOversellViolations() as $violation) {
            $this->logger->error(
                'OVERSELL: resource {resourceId} window {window} has {committed} committed against capacity {capacity}.',
                $violation,
            );
        }
    }
}
