<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

/**
 * Time-based rotating ticket codes — TOTP (RFC 6238) applied to QR tickets.
 *
 * The QR a holder displays no longer carries a static scan token; it carries
 * a short-lived code derived from the ticket's secret and the current time
 * window (default 30s). A screenshot is worthless after the window passes —
 * this is the "rotating barcode" mechanism transit/event apps use against
 * casual ticket sharing.
 *
 * Design choices:
 * - The HMAC key is the ticket's EXISTING scan token (already stored
 *   encrypted at rest as scan_token_cipher). No second secret to provision —
 *   both the display endpoint and the scanner verification decrypt it
 *   server-side, so the raw secret never reaches a client.
 * - Verification accepts the current window ±1 to tolerate clock drift
 *   between the holder's device and the server.
 * - The wire format `FIBR1:<ticketNumber>:<code>` is what the QR encodes and
 *   the scanner forwards verbatim — self-describing and regex-validatable on
 *   both ends. The window is NEVER trusted from the client; the server
 *   recomputes the HMAC for each candidate window and finds the match, so a
 *   client cannot pin a future/past window.
 *
 * Replay across windows is the caller's responsibility (single-use guard on
 * the ticket's last accepted window) — this service is pure and stateless.
 */
class RotatingCodeService
{
    public const DEFAULT_INTERVAL_SECONDS = 30;
    public const WIRE_PREFIX = 'FIBR1';

    /**
     * The accepted drift in either direction, in windows. ±1 window covers
     * the realistic phone/server clock skew without widening the attack
     * surface meaningfully (a leaked code is valid for at most 3 windows).
     */
    private const DRIFT_WINDOWS = 1;

    private const CODE_HEX_LENGTH = 16; // 64-bit truncation — ample for a QR

    /**
     * The wire string a rotating ticket's QR encodes for window `now`.
     */
    public function wireToken(
        string $ticketNumber,
        string $scanToken,
        int $interval,
        int $now,
    ): string {
        $window = $this->windowAt($interval, $now);

        return sprintf('%s:%s:%s', self::WIRE_PREFIX, $ticketNumber, $this->code($scanToken, $window));
    }

    /**
     * Seconds remaining in the current window — lets the display layer time
     * its refresh exactly on the window boundary.
     */
    public function secondsUntilNextWindow(
        int $interval,
        int $now,
    ): int {
        $step = $this->step($interval);

        return $step - ($now % $step);
    }

    /**
     * Verifies a submitted code against the secret for the windows around
     * `now` (current ±1). Returns the MATCHED window number (for the
     * caller's single-use guard), or null when no candidate window matches.
     *
     * Constant-time comparison per candidate — a mismatching code must not
     * leak how close it was via timing.
     */
    public function verify(
        string $code,
        string $scanToken,
        int $interval,
        int $now,
    ): ?int {
        if (!preg_match('/^[0-9a-f]{' . self::CODE_HEX_LENGTH . '}$/', $code)) {
            return null;
        }

        $current = $this->windowAt($interval, $now);

        for ($offset = -self::DRIFT_WINDOWS; $offset <= self::DRIFT_WINDOWS; ++$offset) {
            $window = $current + $offset;

            if (hash_equals($this->code($scanToken, $window), $code)) {
                return $window;
            }
        }

        return null;
    }

    /**
     * Parses a `FIBR1:<ticketNumber>:<code>` wire string. Returns
     * [ticketNumber, code] or null when the shape is wrong.
     *
     * @return array{0: string, 1: string}|null
     */
    public function parseWireToken(string $wire): ?array
    {
        if (!preg_match('/^' . self::WIRE_PREFIX . ':([A-Za-z0-9-]{1,40}):([0-9a-f]{' . self::CODE_HEX_LENGTH . '})$/', $wire, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    public function normalizeInterval(?int $interval): int
    {
        // Bound to a sane range: too short flaps faster than a scan, too long
        // defeats the anti-sharing purpose.
        if ($interval === null || $interval < 10) {
            return self::DEFAULT_INTERVAL_SECONDS;
        }

        return min(120, $interval);
    }

    private function code(
        string $scanToken,
        int $window,
    ): string {
        // HOTP-style: HMAC over the 8-byte big-endian window counter, keyed
        // by the ticket secret, truncated to 64 bits of hex.
        $hmac = hash_hmac('sha256', pack('J', $window), $scanToken, true);

        return substr(bin2hex($hmac), 0, self::CODE_HEX_LENGTH);
    }

    private function windowAt(
        int $interval,
        int $now,
    ): int {
        return intdiv($now, $this->step($interval));
    }

    private function step(int $interval): int
    {
        return max(1, $this->normalizeInterval($interval));
    }
}
