<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Registers the "Ticket" document type so ticket PDFs appear as regular
 * order documents in the administration (order detail → documents).
 * The document number reuses the ticket number range (T…).
 */
class Migration1780759666CreateTicketDocumentType extends MigrationStep
{
    private const TECHNICAL_NAME = 'fib_booking_ticket';

    public function getCreationTimestamp(): int
    {
        return 1780759666;
    }

    public function update(Connection $connection): void
    {
        $typeId = $connection->fetchOne(
            'SELECT id FROM document_type WHERE technical_name = :name',
            ['name' => self::TECHNICAL_NAME],
        );

        if ($typeId === false) {
            $typeId = Uuid::randomBytes();
            $connection->insert('document_type', [
                'id' => $typeId,
                'technical_name' => self::TECHNICAL_NAME,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        // Name translations for every installed language (fallback: "Ticket").
        $connection->executeStatement(
            <<<'SQL'
                INSERT IGNORE INTO document_type_translation (document_type_id, language_id, name, created_at)
                SELECT :typeId, language.id, 'Ticket', NOW(3)
                FROM language
            SQL,
            ['typeId' => $typeId],
        );

        $configExists = $connection->fetchOne(
            'SELECT id FROM document_base_config WHERE document_type_id = :typeId AND global = 1',
            ['typeId' => $typeId],
        );

        if ($configExists === false) {
            $connection->insert('document_base_config', [
                'id' => Uuid::randomBytes(),
                'name' => self::TECHNICAL_NAME,
                'filename_prefix' => 'ticket_',
                'global' => 1,
                'document_type_id' => $typeId,
                'config' => json_encode([
                    'displayHeader' => true,
                    'displayFooter' => false,
                    'displayPageCount' => false,
                    'pageOrientation' => 'portrait',
                    'pageSize' => 'a4',
                ], \JSON_THROW_ON_ERROR),
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
