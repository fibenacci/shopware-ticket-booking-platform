<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use FibBookingSystem\Core\Domain\Security\TokenCipher;
use RuntimeException;

/**
 * Owns the cryptographic side of a rotating-QR scan, kept out of
 * TicketScanService so that class stays a pure verdict orchestrator.
 *
 * Given a ticket's stored row, it decrypts the scan token (the TOTP key),
 * checks the presented code against the time windows around `now`, and
 * enforces the single-use guard (a window already consumed on this ticket is
 * rejected). Returns the matched window number for the caller to persist as
 * the new high-water mark, or null on any failure (disabled, undecryptable,
 * wrong/expired code, replay).
 */
class RotatingScanVerifier
{
    public function __construct(
        private readonly RotatingCodeService $rotatingCodeService,
        private readonly TokenCipher $tokenCipher,
    ) {
    }

    public function matchWindow(
        string $code,
        bool $rotatingEnabled,
        ?string $scanTokenCipher,
        ?int $interval,
        ?int $lastWindow,
        int $now,
    ): ?int {
        if (!$rotatingEnabled || !is_string($scanTokenCipher) || $scanTokenCipher === '') {
            return null;
        }

        try {
            $scanToken = $this->tokenCipher->decrypt($scanTokenCipher);
        } catch (RuntimeException) {
            return null;
        }

        $window = $this->rotatingCodeService->verify(
            $code,
            $scanToken,
            $this->rotatingCodeService->normalizeInterval($interval),
            $now,
        );

        if ($window === null) {
            return null;
        }

        // Single-use: a code for the current or any earlier window cannot be
        // redeemed twice — kills real-time relaying of a screenshotted code.
        if ($lastWindow !== null && $window <= $lastWindow) {
            return null;
        }

        return $window;
    }
}
