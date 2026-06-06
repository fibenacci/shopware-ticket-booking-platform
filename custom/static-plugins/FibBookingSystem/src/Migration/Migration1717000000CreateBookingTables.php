<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1717000000CreateBookingTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1717000000;
    }

    public function update(Connection $connection): void
    {
        $this->createResourceTable($connection);
        $this->createHoldTable($connection);
        $this->createReservationTable($connection);
        $this->createTicketTable($connection);
        $this->createProductConfigTable($connection);
        $this->createNumberRanges($connection);
        $this->createTicketMailTemplate($connection);
    }

    private function createResourceTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_resource` (
                `id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NULL,
                `name` VARCHAR(255) NOT NULL,
                `technical_name` VARCHAR(255) NOT NULL,
                `capacity` INT NOT NULL DEFAULT 1,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `configuration` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_resource.technical_name` (`technical_name`),
                KEY `idx.fib_booking_resource.product_id` (`product_id`),
                CONSTRAINT `json.fib_booking_resource.configuration` CHECK (JSON_VALID(`configuration`)),
                CONSTRAINT `fk.fib_booking_resource.product_id` FOREIGN KEY (`product_id`)
                REFERENCES `product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    private function createHoldTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_hold` (
                `id` BINARY(16) NOT NULL,
                `resource_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `customer_id` BINARY(16) NULL,
                `token` VARCHAR(128) NOT NULL,
                `starts_at` DATETIME(3) NOT NULL,
                `ends_at` DATETIME(3) NOT NULL,
                `expires_at` DATETIME(3) NOT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `status` VARCHAR(32) NOT NULL,
                `payload` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_hold.token` (`token`),
                KEY `idx.fib_booking_hold.resource_window` (`resource_id`, `starts_at`, `ends_at`, `status`),
                KEY `idx.fib_booking_hold.expires_at` (`expires_at`, `status`),
                CONSTRAINT `json.fib_booking_hold.payload` CHECK (JSON_VALID(`payload`)),
                CONSTRAINT `fk.fib_booking_hold.resource_id` FOREIGN KEY (`resource_id`)
                REFERENCES `fib_booking_resource` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_hold.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                REFERENCES `sales_channel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_hold.customer_id` FOREIGN KEY (`customer_id`)
                REFERENCES `customer` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    private function createReservationTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_reservation` (
                `id` BINARY(16) NOT NULL,
                `resource_id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NULL,
                `order_version_id` BINARY(16) NULL,
                `order_line_item_id` BINARY(16) NULL,
                `order_line_item_version_id` BINARY(16) NULL,
                `customer_id` BINARY(16) NULL,
                `hold_id` BINARY(16) NULL,
                `booking_number` VARCHAR(64) NOT NULL,
                `starts_at` DATETIME(3) NOT NULL,
                `ends_at` DATETIME(3) NOT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `status` VARCHAR(32) NOT NULL,
                `payload` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_reservation.booking_number` (`booking_number`),
                KEY `idx.fib_booking_reservation.resource_window` (`resource_id`, `starts_at`, `ends_at`, `status`),
                KEY `idx.fib_booking_reservation.order_id` (`order_id`, `order_version_id`),
                KEY `idx.fib_booking_reservation.order_line_item_id` (`order_line_item_id`, `order_line_item_version_id`),
                CONSTRAINT `json.fib_booking_reservation.payload` CHECK (JSON_VALID(`payload`)),
                CONSTRAINT `fk.fib_booking_reservation.resource_id` FOREIGN KEY (`resource_id`)
                REFERENCES `fib_booking_resource` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_reservation.order_id` FOREIGN KEY (`order_id`, `order_version_id`)
                REFERENCES `order` (`id`, `version_id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_reservation.order_line_item_id` FOREIGN KEY (`order_line_item_id`, `order_line_item_version_id`)
                REFERENCES `order_line_item` (`id`, `version_id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_reservation.customer_id` FOREIGN KEY (`customer_id`)
                REFERENCES `customer` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_reservation.hold_id` FOREIGN KEY (`hold_id`)
                REFERENCES `fib_booking_hold` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    private function createTicketTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_ticket` (
                `id` BINARY(16) NOT NULL,
                `reservation_id` BINARY(16) NOT NULL,
                `ticket_number` VARCHAR(64) NOT NULL,
                `scan_token_hash` CHAR(64) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `issued_at` DATETIME(3) NOT NULL,
                `sent_at` DATETIME(3) NULL,
                `scanned_at` DATETIME(3) NULL,
                `expires_at` DATETIME(3) NULL,
                `payload` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_ticket.ticket_number` (`ticket_number`),
                UNIQUE KEY `uniq.fib_booking_ticket.scan_token_hash` (`scan_token_hash`),
                KEY `idx.fib_booking_ticket.reservation_id` (`reservation_id`),
                KEY `idx.fib_booking_ticket.status` (`status`),
                CONSTRAINT `json.fib_booking_ticket.payload` CHECK (JSON_VALID(`payload`)),
                CONSTRAINT `fk.fib_booking_ticket.reservation_id` FOREIGN KEY (`reservation_id`)
                REFERENCES `fib_booking_reservation` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    private function createProductConfigTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_product_config` (
                `id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `resource_id` BINARY(16) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `slot_minutes` INT NOT NULL DEFAULT 60,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_product_config.product` (`product_id`, `product_version_id`),
                KEY `idx.fib_booking_product_config.resource_id` (`resource_id`),
                CONSTRAINT `fk.fib_booking_product_config.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_product_config.resource_id` FOREIGN KEY (`resource_id`)
                REFERENCES `fib_booking_resource` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createNumberRanges(Connection $connection): void
    {
        $this->createNumberRange(
            $connection,
            $this->stableId('number-range-type.reservation'),
            $this->stableId('number-range.reservation'),
            $this->stableId('number-range-state.reservation'),
            'fib_booking_reservation',
            'B{n}',
            10000,
            'Buchungsnummer',
            'Booking number',
        );

        $this->createNumberRange(
            $connection,
            $this->stableId('number-range-type.ticket'),
            $this->stableId('number-range.ticket'),
            $this->stableId('number-range-state.ticket'),
            'fib_booking_ticket',
            'T{n}',
            10000,
            'Ticketnummer',
            'Ticket number',
        );
    }

    private function createNumberRange(
        Connection $connection,
        string $typeId,
        string $numberRangeId,
        string $numberRangeStateId,
        string $technicalName,
        string $pattern,
        int $start,
        string $germanName,
        string $englishName,
    ): void {
        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `number_range_type` (`id`, `technical_name`, `global`, `created_at`)
                VALUES (UNHEX(:typeId), :technicalName, 1, UTC_TIMESTAMP(3))
            SQL, [
            'typeId' => $typeId,
            'technicalName' => $technicalName,
        ]);

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `number_range` (`id`, `type_id`, `global`, `pattern`, `start`, `created_at`)
                VALUES (UNHEX(:numberRangeId), UNHEX(:typeId), 1, :pattern, :start, UTC_TIMESTAMP(3))
            SQL, [
            'numberRangeId' => $numberRangeId,
            'typeId' => $typeId,
            'pattern' => $pattern,
            'start' => $start,
        ]);

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `number_range_state` (`id`, `number_range_id`, `last_value`, `created_at`)
                VALUES (UNHEX(:stateId), UNHEX(:numberRangeId), :lastValue, UTC_TIMESTAMP(3))
            SQL, [
            'stateId' => $numberRangeStateId,
            'numberRangeId' => $numberRangeId,
            'lastValue' => $start - 1,
        ]);

        $this->createNumberRangeTranslations($connection, $typeId, $numberRangeId, 'de-DE', $germanName);
        $this->createNumberRangeTranslations($connection, $typeId, $numberRangeId, 'en-GB', $englishName);
    }

    private function createNumberRangeTranslations(
        Connection $connection,
        string $typeId,
        string $numberRangeId,
        string $localeCode,
        string $name,
    ): void {
        $languageId = $this->fetchLanguageId($connection, $localeCode);

        if ($languageId === null) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `number_range_type_translation`
                (`number_range_type_id`, `language_id`, `type_name`, `created_at`)
                VALUES
                (UNHEX(:typeId), :languageId, :name, UTC_TIMESTAMP(3))
            SQL, [
            'typeId' => $typeId,
            'languageId' => $languageId,
            'name' => $name,
        ]);

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `number_range_translation`
                (`number_range_id`, `language_id`, `name`, `description`, `created_at`)
                VALUES
                (UNHEX(:numberRangeId), :languageId, :name, :description, UTC_TIMESTAMP(3))
            SQL, [
            'numberRangeId' => $numberRangeId,
            'languageId' => $languageId,
            'name' => $name,
            'description' => $name,
        ]);
    }

    private function createTicketMailTemplate(Connection $connection): void
    {
        $mailTemplateTypeId = $this->stableId('mail-template-type.ticket');
        $mailTemplateId = $this->stableId('mail-template.ticket');

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `mail_template_type` (`id`, `technical_name`, `available_entities`, `created_at`)
                VALUES (
                UNHEX(:typeId),
                :technicalName,
                :availableEntities,
                UTC_TIMESTAMP(3)
                )
            SQL, [
            'typeId' => $mailTemplateTypeId,
            'technicalName' => 'fib_booking_ticket_mail',
            'availableEntities' => json_encode([
                'booking' => 'booking',
                'ticket' => 'ticket',
            ], JSON_THROW_ON_ERROR),
        ]);

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `mail_template` (`id`, `mail_template_type_id`, `system_default`, `created_at`)
                VALUES (UNHEX(:templateId), UNHEX(:typeId), 1, UTC_TIMESTAMP(3))
            SQL, [
            'templateId' => $mailTemplateId,
            'typeId' => $mailTemplateTypeId,
        ]);

        $this->createTicketMailTemplateTranslation(
            $connection,
            $mailTemplateTypeId,
            $mailTemplateId,
            'de-DE',
            'Buchungsticket mit QR-Code',
            'Ihr Ticket {{ ticket.number }}',
            'Hallo, Ihr Ticket fuer die Buchung {{ booking.number }} ist bereit. Bitte zeigen Sie den QR-Code am Ticketschalter vor.',
            '<p>Hallo,</p><p>Ihr Ticket fuer die Buchung <strong>{{ booking.number }}</strong> ist bereit.</p><p>Bitte zeigen Sie diesen QR-Code am Ticketschalter vor:</p><p><img src="{{ ticket.qrCodeDataUri }}" alt="QR-Code fuer Ticket {{ ticket.number }}" width="240" height="240"></p><p>Ticketnummer: {{ ticket.number }}</p>'
        );

        $this->createTicketMailTemplateTranslation(
            $connection,
            $mailTemplateTypeId,
            $mailTemplateId,
            'en-GB',
            'Booking ticket with QR code',
            'Your ticket {{ ticket.number }}',
            'Hello, your ticket for booking {{ booking.number }} is ready. Please show the QR code at the ticket counter.',
            '<p>Hello,</p><p>Your ticket for booking <strong>{{ booking.number }}</strong> is ready.</p><p>Please show this QR code at the ticket counter:</p><p><img src="{{ ticket.qrCodeDataUri }}" alt="QR code for ticket {{ ticket.number }}" width="240" height="240"></p><p>Ticket number: {{ ticket.number }}</p>'
        );
    }

    private function createTicketMailTemplateTranslation(
        Connection $connection,
        string $mailTemplateTypeId,
        string $mailTemplateId,
        string $localeCode,
        string $typeName,
        string $subject,
        string $contentPlain,
        string $contentHtml,
    ): void {
        $languageId = $this->fetchLanguageId($connection, $localeCode);

        if ($languageId === null) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `mail_template_type_translation`
                (`mail_template_type_id`, `language_id`, `name`, `created_at`)
                VALUES
                (UNHEX(:typeId), :languageId, :name, UTC_TIMESTAMP(3))
            SQL, [
            'typeId' => $mailTemplateTypeId,
            'languageId' => $languageId,
            'name' => $typeName,
        ]);

        $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `mail_template_translation`
                (`mail_template_id`, `language_id`, `sender_name`, `subject`, `description`, `content_html`, `content_plain`, `created_at`)
                VALUES
                (UNHEX(:templateId), :languageId, :senderName, :subject, :description, :contentHtml, :contentPlain, UTC_TIMESTAMP(3))
            SQL, [
            'templateId' => $mailTemplateId,
            'languageId' => $languageId,
            'senderName' => '{{ salesChannel.name }}',
            'subject' => $subject,
            'description' => $typeName,
            'contentHtml' => $contentHtml,
            'contentPlain' => $contentPlain,
        ]);
    }

    private function fetchLanguageId(Connection $connection, string $localeCode): ?string
    {
        $languageId = $connection->fetchOne(<<<'SQL'
                SELECT `language`.`id`
                FROM `language`
                INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
                WHERE `locale`.`code` = :localeCode
                LIMIT 1
            SQL, [
            'localeCode' => $localeCode,
        ]);

        return is_string($languageId) ? $languageId : null;
    }

    private function stableId(string $name): string
    {
        return Uuid::fromStringToHex('fib-booking-system.' . $name);
    }
}
