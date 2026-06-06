<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use ZipArchive;

/**
 * Generates signed Apple Wallet event tickets (.pkpass).
 *
 * A .pkpass is a ZIP containing pass.json, icon assets, a manifest with SHA-1
 * digests and a detached PKCS#7 signature of that manifest, created with the
 * Apple "Pass Type ID" certificate (plus the Apple WWDR intermediate).
 *
 * Configuration (plugin config / admin):
 * - certificate path (PKCS#12 .p12) + password
 * - pass type identifier + team identifier
 * - WWDR certificate path (PEM)
 */
class AppleWalletPassGenerator
{
    private const CONFIG_PREFIX = 'FibBookingSystem.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly ?string $projectDir = null,
    ) {
    }

    public function isConfigured(): bool
    {
        $certPath = $this->resolvePath($this->getString('appleWalletCertificatePath'));
        $wwdrPath = $this->resolvePath($this->getString('appleWalletWwdrPath'));

        return $certPath !== null
            && is_readable($certPath)
            && $wwdrPath !== null
            && is_readable($wwdrPath)
            && $this->getString('appleWalletPassTypeId') !== ''
            && $this->getString('appleWalletTeamId') !== '';
    }

    public function generate(TicketWalletData $data): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Apple Wallet is not configured.');
        }

        $files = [
            'pass.json' => json_encode($this->buildPassDefinition($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'icon.png' => $this->loadIcon('icon.png'),
            'icon@2x.png' => $this->loadIcon('icon@2x.png'),
        ];

        $manifest = [];
        foreach ($files as $name => $content) {
            $manifest[$name] = sha1($content);
        }
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);

        $signature = $this->signManifest($manifestJson);

        return $this->buildZip($files + ['manifest.json' => $manifestJson, 'signature' => $signature]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPassDefinition(TicketWalletData $data): array
    {
        return [
            'formatVersion' => 1,
            'passTypeIdentifier' => $this->getString('appleWalletPassTypeId'),
            'teamIdentifier' => $this->getString('appleWalletTeamId'),
            'serialNumber' => $data->ticketNumber,
            'organizationName' => $this->getString('walletOrganizationName') ?: 'FIB Booking',
            'description' => sprintf('Ticket %s — %s', $data->ticketNumber, $data->window->resourceName),
            'relevantDate' => $data->window->startsAt->format('c'),
            'expirationDate' => $data->window->endsAt->modify('+1 day')->format('c'),
            'barcodes' => [
                [
                    'format' => 'PKBarcodeFormatQR',
                    'message' => $data->qrPayload,
                    'messageEncoding' => 'iso-8859-1',
                ],
            ],
            'eventTicket' => [
                'primaryFields' => [
                    ['key' => 'event', 'label' => 'EVENT', 'value' => $data->window->resourceName],
                ],
                'secondaryFields' => [
                    ['key' => 'starts', 'label' => 'START', 'value' => $data->window->startsAt->format('c'), 'dateStyle' => 'PKDateStyleMedium', 'timeStyle' => 'PKDateStyleShort'],
                    ['key' => 'quantity', 'label' => 'GUESTS', 'value' => (string) $data->window->quantity],
                ],
                'auxiliaryFields' => [
                    ['key' => 'booking', 'label' => 'BOOKING', 'value' => $data->bookingNumber],
                    ['key' => 'ticket', 'label' => 'TICKET', 'value' => $data->ticketNumber],
                ],
            ],
            'backgroundColor' => 'rgb(28, 30, 38)',
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(160, 170, 190)',
        ];
    }

    private function signManifest(string $manifestJson): string
    {
        $certPath = (string) $this->resolvePath($this->getString('appleWalletCertificatePath'));
        $certPassword = $this->getString('appleWalletCertificatePassword');
        $wwdrPath = (string) $this->resolvePath($this->getString('appleWalletWwdrPath'));

        $p12 = file_get_contents($certPath);
        if ($p12 === false) {
            throw new RuntimeException('Apple Wallet certificate could not be read.');
        }

        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $certPassword)) {
            throw new RuntimeException('Apple Wallet certificate could not be unlocked.');
        }

        /** @var array<string, mixed> $certs */
        $certificate = $certs['cert'] ?? null;
        $privateKey = $certs['pkey'] ?? null;

        if (!is_string($certificate) || !is_string($privateKey)) {
            throw new RuntimeException('Apple Wallet certificate bundle is incomplete (cert/pkey missing).');
        }

        $manifestFile = $this->tempFile($manifestJson);
        $signatureFile = tempnam(sys_get_temp_dir(), 'fib-wallet-sig');
        if ($signatureFile === false) {
            throw new RuntimeException('Could not allocate temp file for the pass signature.');
        }

        try {
            $signed = openssl_pkcs7_sign(
                $manifestFile,
                $signatureFile,
                $certificate,
                [$privateKey, $certPassword],
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
                $wwdrPath,
            );

            if (!$signed) {
                throw new RuntimeException('Signing the pass manifest failed.');
            }

            // openssl writes S/MIME output — extract the raw DER signature part.
            $smime = (string) file_get_contents($signatureFile);

            return $this->extractDerSignature($smime);
        } finally {
            $this->removeTempFile($manifestFile);
            $this->removeTempFile($signatureFile);
        }
    }

    private function extractDerSignature(string $smime): string
    {
        $matches = [];
        if (!preg_match('/base64\R\R(.*?)\R\R----/s', $smime, $matches)) {
            // Fallback: take everything after the double newline following the headers.
            $parts = preg_split('/\R\R/', $smime, 3);
            if (!is_array($parts) || !isset($parts[1])) {
                throw new RuntimeException('Could not extract the pass signature.');
            }
            $matches[1] = $parts[1];
        }

        $der = base64_decode(trim($matches[1]), true);
        if ($der === false) {
            throw new RuntimeException('Could not decode the pass signature.');
        }

        return $der;
    }

    /**
     * @param array<string, string> $files
     */
    private function buildZip(array $files): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'fib-pkpass');
        if ($zipPath === false) {
            throw new RuntimeException('Could not allocate temp file for the pass bundle.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the pass bundle.');
            }

            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            $bundle = file_get_contents($zipPath);
            if ($bundle === false) {
                throw new RuntimeException('Could not read the pass bundle.');
            }

            return $bundle;
        } finally {
            $this->removeTempFile($zipPath);
        }
    }

    private function loadIcon(string $name): string
    {
        $custom = $this->resolvePath($this->getString('walletIconPath'));
        if ($custom !== null && is_readable($custom)) {
            $content = file_get_contents($custom);
            if ($content !== false) {
                return $content;
            }
        }

        $bundled = __DIR__ . '/../../../Resources/wallet/' . $name;
        $content = file_get_contents($bundled);
        if ($content === false) {
            throw new RuntimeException(sprintf('Bundled wallet icon "%s" is missing.', $name));
        }

        return $content;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fib-wallet');
        if ($path === false || file_put_contents($path, $content) === false) {
            throw new RuntimeException('Could not write temp file.');
        }

        return $path;
    }

    private function removeTempFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function resolvePath(string $configured): ?string
    {
        if ($configured === '') {
            return null;
        }

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return ($this->projectDir ?? getcwd() ?: '.') . '/' . $configured;
    }

    private function getString(string $key): string
    {
        $value = $this->systemConfig->get(self::CONFIG_PREFIX . $key);

        return is_string($value) ? trim($value) : '';
    }
}
