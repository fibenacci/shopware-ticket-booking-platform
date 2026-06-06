<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use RuntimeException;

/**
 * Stateless, expiring HMAC signatures for wallet download URLs.
 *
 * The signature covers provider, ticket id and expiry timestamp with a key
 * derived from the application secret. Verification is constant-time and
 * never reveals which part failed (uniform false).
 */
class WalletLinkSigner
{
    private readonly string $key;

    public function __construct(string $appSecret)
    {
        if ($appSecret === '') {
            throw new RuntimeException('WalletLinkSigner requires a non-empty application secret.');
        }

        $this->key = hash_hkdf('sha256', $appSecret, 32, 'fib-booking-wallet-link');
    }

    public function sign(
        string $provider,
        string $ticketId,
        int $expiresAt,
    ): string {
        return hash_hmac('sha256', $this->payload($provider, $ticketId, $expiresAt), $this->key);
    }

    public function verify(
        string $provider,
        string $ticketId,
        int $expiresAt,
        string $signature,
    ): bool {
        if ($expiresAt < time()) {
            return false;
        }

        if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }

        return hash_equals($this->sign($provider, $ticketId, $expiresAt), $signature);
    }

    private function payload(
        string $provider,
        string $ticketId,
        int $expiresAt,
    ): string {
        return sprintf('wallet|%s|%s|%d', $provider, strtolower($ticketId), $expiresAt);
    }
}
