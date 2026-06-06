<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use FibBookingSystem\Core\Domain\Reservation\BookingHoldExpirationService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationExpirationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One maintenance tick for both inventory-releasing expiries: overdue holds
 * (minutes-scale TTL on the hold row) and stale pending_payment reservations
 * (hours-scale TTL via `pendingReservationTtlHours`, default 72, 0 = off).
 */
#[AsMessageHandler(handles: ExpireBookingHoldsTask::class)]
class ExpireBookingHoldsTaskHandler extends ScheduledTaskHandler
{
    private const DEFAULT_PENDING_RESERVATION_TTL_HOURS = 72;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly BookingHoldExpirationService $holdExpirationService,
        private readonly BookingReservationExpirationService $reservationExpirationService,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->holdExpirationService->expireOverdueHolds();
        $this->reservationExpirationService->expireOverduePendingReservations($this->pendingReservationTtlHours());
    }

    private function pendingReservationTtlHours(): int
    {
        // Unset config falls back to the default; an explicit 0 (= disabled)
        // is respected — that distinction is why getInt() is not used here.
        $raw = $this->systemConfig->get('FibBookingSystem.config.pendingReservationTtlHours');

        return is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULT_PENDING_RESERVATION_TTL_HOURS;
    }
}
