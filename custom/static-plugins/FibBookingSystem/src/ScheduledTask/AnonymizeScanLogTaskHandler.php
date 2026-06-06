<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use FibBookingSystem\Core\Domain\Ticket\BookingScanLogRetentionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: AnonymizeScanLogTask::class)]
class AnonymizeScanLogTaskHandler extends ScheduledTaskHandler
{
    private const DEFAULT_RETENTION_DAYS = 180;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly BookingScanLogRetentionService $retentionService,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->retentionService->anonymizeOlderThan($this->retentionDays());
    }

    private function retentionDays(): int
    {
        // Unset config falls back to the default; an explicit 0 (= disabled)
        // is respected — that distinction is why getInt() is not used here.
        $raw = $this->systemConfig->get('FibBookingSystem.config.scanLogRetentionDays');

        return is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULT_RETENTION_DAYS;
    }
}
