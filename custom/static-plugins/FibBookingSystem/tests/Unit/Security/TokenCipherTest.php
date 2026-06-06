<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Security;

use FibBookingSystem\Core\Domain\Security\TokenCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TokenCipherTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = new TokenCipher('test-secret');
        $token = bin2hex(random_bytes(32));

        $encrypted = $cipher->encrypt($token);

        static::assertNotSame($token, $encrypted);
        static::assertSame($token, $cipher->decrypt($encrypted));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        $cipher = new TokenCipher('test-secret');

        static::assertNotSame($cipher->encrypt('token'), $cipher->encrypt('token'));
    }

    public function testDecryptFailsWithWrongSecret(): void
    {
        $encrypted = (new TokenCipher('secret-a'))->encrypt('token');

        $this->expectException(RuntimeException::class);
        (new TokenCipher('secret-b'))->decrypt($encrypted);
    }

    public function testDecryptFailsOnTamperedCiphertext(): void
    {
        $cipher = new TokenCipher('test-secret');
        $raw = base64_decode($cipher->encrypt('token'), true);
        static::assertIsString($raw);

        $tampered = $raw;
        $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(RuntimeException::class);
        $cipher->decrypt(base64_encode($tampered));
    }

    public function testRejectsEmptySecret(): void
    {
        $this->expectException(RuntimeException::class);
        new TokenCipher('');
    }
}
