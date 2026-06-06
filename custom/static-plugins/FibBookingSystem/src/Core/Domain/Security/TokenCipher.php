<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Security;

use RuntimeException;

/**
 * AES-256-GCM encryption for scan tokens at rest.
 *
 * The key is derived from the application secret (HKDF-like, domain-separated),
 * so a database dump alone never exposes usable scan tokens. Output format:
 * base64(nonce[12] . tag[16] . ciphertext).
 */
class TokenCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(string $appSecret)
    {
        if ($appSecret === '') {
            throw new RuntimeException('TokenCipher requires a non-empty application secret.');
        }

        $this->key = hash_hkdf('sha256', $appSecret, 32, 'fib-booking-token-cipher');
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new RuntimeException('Token encryption failed.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Token decryption failed.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Token decryption failed.');
        }

        return $plaintext;
    }
}
