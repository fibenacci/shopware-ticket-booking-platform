<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class QrCodeGenerator
{
    public function generateDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 8,
        ]);

        $rendered = (new QRCode($options))->render($payload);

        if (!is_string($rendered)) {
            throw new RuntimeException('QR code rendering did not return a data URI string.');
        }

        return $rendered;
    }
}
