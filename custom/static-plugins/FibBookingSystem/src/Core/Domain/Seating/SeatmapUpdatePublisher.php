<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Seating;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Pushes "seat states changed" signals for live seat maps (SSE via Mercure,
 * docs/SEATING_PLAN.md phase 5). The payload is intentionally just a poke —
 * subscribers re-fetch the seatmap read model, so the push can never diverge
 * from the database truth.
 *
 * defer()/flush(): claims change INSIDE transactions — publishing must only
 * happen after commit, or subscribers would re-fetch state that may still
 * roll back. Callers defer inside the transaction and flush after it.
 *
 * Publishing is best-effort by design: a missing/unreachable hub degrades to
 * the picker's polling fallback and must NEVER break booking flows — failures
 * are logged (warning) instead of thrown.
 */
class SeatmapUpdatePublisher
{
    public const TOPIC_PREFIX = 'fib-booking/seatmap/';

    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function defer(string $slotId): void
    {
        $this->pending[strtolower($slotId)] = true;
    }

    public function flush(): void
    {
        $slotIds = array_keys($this->pending);
        $this->pending = [];

        foreach ($slotIds as $slotId) {
            try {
                $this->hub->publish(new Update(
                    self::TOPIC_PREFIX . $slotId,
                    json_encode(['slotId' => $slotId], \JSON_THROW_ON_ERROR),
                ));
            } catch (Throwable $exception) {
                // Hub down or not configured — the picker keeps polling.
                $this->logger->warning('Seatmap push for slot {slotId} failed: {message}', [
                    'slotId' => $slotId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /** defer + flush for call sites that are already outside a transaction. */
    public function publish(string $slotId): void
    {
        $this->defer($slotId);
        $this->flush();
    }
}
