<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Wallet;

use FibBookingSystem\Core\Domain\Wallet\WalletLinkSigner;
use PHPUnit\Framework\TestCase;

class WalletLinkSignerTest extends TestCase
{
    public function testSignAndVerify(): void
    {
        $signer = new WalletLinkSigner('test-secret');
        $expiresAt = time() + 3600;

        $signature = $signer->sign('apple', 'a1b2c3', $expiresAt);

        static::assertTrue($signer->verify('apple', 'a1b2c3', $expiresAt, $signature));
    }

    public function testVerifyFailsForDifferentProvider(): void
    {
        $signer = new WalletLinkSigner('test-secret');
        $expiresAt = time() + 3600;

        $signature = $signer->sign('apple', 'a1b2c3', $expiresAt);

        static::assertFalse($signer->verify('google', 'a1b2c3', $expiresAt, $signature));
    }

    public function testVerifyFailsForDifferentTicket(): void
    {
        $signer = new WalletLinkSigner('test-secret');
        $expiresAt = time() + 3600;

        $signature = $signer->sign('apple', 'a1b2c3', $expiresAt);

        static::assertFalse($signer->verify('apple', 'ffffff', $expiresAt, $signature));
    }

    public function testVerifyFailsWhenExpired(): void
    {
        $signer = new WalletLinkSigner('test-secret');
        $expiresAt = time() - 1;

        $signature = $signer->sign('apple', 'a1b2c3', $expiresAt);

        static::assertFalse($signer->verify('apple', 'a1b2c3', $expiresAt, $signature));
    }

    public function testVerifyFailsWhenExpiryIsTampered(): void
    {
        $signer = new WalletLinkSigner('test-secret');
        $expiresAt = time() + 3600;

        $signature = $signer->sign('apple', 'a1b2c3', $expiresAt);

        static::assertFalse($signer->verify('apple', 'a1b2c3', $expiresAt + 86400, $signature));
    }

    public function testVerifyFailsForMalformedSignature(): void
    {
        $signer = new WalletLinkSigner('test-secret');

        static::assertFalse($signer->verify('apple', 'a1b2c3', time() + 3600, 'not-a-signature'));
    }

    public function testSignaturesDifferBetweenSecrets(): void
    {
        $expiresAt = time() + 3600;

        static::assertNotSame(
            (new WalletLinkSigner('secret-a'))->sign('apple', 'a1b2c3', $expiresAt),
            (new WalletLinkSigner('secret-b'))->sign('apple', 'a1b2c3', $expiresAt),
        );
    }
}
