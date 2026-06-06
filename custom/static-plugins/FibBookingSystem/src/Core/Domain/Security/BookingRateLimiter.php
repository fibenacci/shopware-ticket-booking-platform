<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Security;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Sliding-window rate limits for the booking surface. Keys are scoped per
 * limiter, so a flood of availability checks cannot starve scans and vice
 * versa. Throws 429 with Retry-After when a bucket is exhausted.
 *
 * Limiters (configured in services.xml):
 * - booking_read:  availability checks                (120 / min / IP)
 * - booking_write: hold creation + add-to-cart        (30 / min / IP)
 * - wallet:        wallet pass downloads              (20 / min / IP)
 * - scan:          ticket scan API                    (120 / min / actor)
 */
class BookingRateLimiter
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const WALLET = 'wallet';
    public const SCAN = 'scan';

    /**
     * @var array<string, RateLimiterFactory>
     */
    private readonly array $factories;

    public function __construct(
        RateLimiterFactory $readFactory,
        RateLimiterFactory $writeFactory,
        RateLimiterFactory $walletFactory,
        RateLimiterFactory $scanFactory,
    ) {
        $this->factories = [
            self::READ => $readFactory,
            self::WRITE => $writeFactory,
            self::WALLET => $walletFactory,
            self::SCAN => $scanFactory,
        ];
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    public function ensureAccepted(string $limiter, ?string $key): void
    {
        $factory = $this->factories[$limiter] ?? null;

        if ($factory === null) {
            return;
        }

        // Hash the key: raw IPs/user ids never become cache keys.
        $limit = $factory->create(hash('sha256', (string) $key))->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests.');
        }
    }
}
