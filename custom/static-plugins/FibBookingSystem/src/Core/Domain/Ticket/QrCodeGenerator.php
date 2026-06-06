<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeGenerator
{
    public function generateDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 8,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
