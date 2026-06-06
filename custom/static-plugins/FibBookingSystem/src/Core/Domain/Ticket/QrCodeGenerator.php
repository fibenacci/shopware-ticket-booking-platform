<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class QrCodeGenerator
{
    public function generateDataUri(string $payload): string
    {
        $options = new QROptions([
            // php-qrcode 5.0.x: `outputType` decides — `outputInterface` is
            // only honored together with outputType CUSTOM. Relying on
            // outputInterface alone silently fell back to SVG markup, which
            // dompdf does not reliably embed in the ticket PDF.
            'outputType' => QROutputInterface::GDIMAGE_PNG,
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
