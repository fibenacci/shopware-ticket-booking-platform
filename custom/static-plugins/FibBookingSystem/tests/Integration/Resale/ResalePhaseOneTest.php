<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Resale;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Resale\BookingResaleException;
use FibBookingSystem\Core\Domain\Resale\BookingResaleService;
use FibBookingSystem\Core\Domain\Resale\ListingMode;
use FibBookingSystem\Core\Domain\Resale\TicketTransferService;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
use FibBookingSystem\Core\Domain\Ticket\RotatingScanVerifier;
use FibBookingSystem\Core\Domain\Ticket\ScanVerdictResolver;
use FibBookingSystem\Core\Domain\Ticket\TicketScanResult;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resale phase 1 against the real schema (docs/RESELL_PLAN.md):
 * the transfer primitive's token rotation and the listing guardrails with
 * their insert-wins uniqueness.
 */
class ResalePhaseOneTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000f001';
    private const RESERVATION_ID = 'f1b0000000000000000000000000f002';
    private const TICKET_ID = 'f1b0000000000000000000000000f003';
    private const OWNER_ID = 'f1b0000000000000000000000000f0c1';
    private const STRANGER_ID = 'f1b0000000000000000000000000f0c2';

    private Connection $connection;
    private Context $context;
    private string $scanToken;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->context = Context::createDefaultContext();

        $this->cleanupFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testTransferRotatesTheTokenAndKillsTheSellersCopy(): void
    {
        $newTicket = $this->createTransferService()->transfer(self::TICKET_ID, $this->context);

        // Snapshots carried, audit chain set.
        static::assertSame('F7', $newTicket->getSeatLabel());
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(replaced_ticket_id)) AS replaced, seat_label, entry_policy, status
                FROM fib_booking_ticket WHERE id = :id
                SQL,
            ['id' => Uuid::fromHexToBytes($newTicket->getId())],
        );
        static::assertIsArray($row);
        static::assertSame(self::TICKET_ID, $row['replaced']);
        static::assertSame('F7', $row['seat_label']);
        static::assertSame('multi', $row['entry_policy']);

        // The seller's old QR is scan-DEAD, the buyer's new one is live.
        $scanService = $this->createScanService();
        static::assertSame(
            TicketScanResult::REVOKED,
            $scanService->scan($this->scanToken, $this->context, 'resale-test', 'phpunit')->verdict,
            'old token must be revoked after transfer',
        );
        static::assertSame(
            TicketScanResult::VALID,
            $scanService->scan($newTicket->getScanToken(), $this->context, 'resale-test', 'phpunit')->verdict,
            'new token must scan valid',
        );
    }

    public function testRevokedTicketsAreNotTransferable(): void
    {
        $this->createTransferService()->transfer(self::TICKET_ID, $this->context);

        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('cannot be transferred');

        // The OLD ticket is revoked now — a second transfer must fail.
        $this->createTransferService()->transfer(self::TICKET_ID, $this->context);
    }

    public function testOnlyOneLiveListingPerTicket(): void
    {
        $service = $this->createResaleService();

        $listingId = $service->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::FIXED_PRICE, 50.0);
        static::assertNotEmpty($listingId);

        try {
            $service->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::FIXED_PRICE, 60.0);
            static::fail('expected LISTING_ALREADY_EXISTS');
        } catch (FibBookingException $exception) {
            static::assertSame(BookingResaleException::LISTING_ALREADY_EXISTS, $exception->getErrorCode());
        }

        // Cancelling releases the unique guard — the ticket is listable again.
        $service->cancelListing($listingId, self::OWNER_ID);
        $second = $service->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::FIXED_PRICE, 55.0);
        static::assertNotEmpty($second);
    }

    public function testOnlyTheOwnerMayListOrCancel(): void
    {
        $service = $this->createResaleService();

        try {
            $service->createListing(self::TICKET_ID, self::STRANGER_ID, ListingMode::FIXED_PRICE, 50.0);
            static::fail('expected TICKET_NOT_LISTABLE');
        } catch (FibBookingException $exception) {
            static::assertSame(BookingResaleException::TICKET_NOT_LISTABLE, $exception->getErrorCode());
        }

        $listingId = $service->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::FIXED_PRICE, 50.0);

        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('Only the seller');
        $service->cancelListing($listingId, self::STRANGER_ID);
    }

    public function testCutoffBlocksListingCloseToTheSlot(): void
    {
        // Slot starts in 30 minutes; cutoff (default) is 60.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_reservation
                SET starts_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 MINUTE)
                WHERE id = :id
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESERVATION_ID)],
        );

        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('too close');

        $this->createResaleService()->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::FIXED_PRICE, 50.0);
    }

    public function testAuctionsRequireAnEndTime(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('end time');

        $this->createResaleService()->createListing(self::TICKET_ID, self::OWNER_ID, ListingMode::AUCTION, 10.0);
    }

    private function createResaleService(): BookingResaleService
    {
        return new BookingResaleService($this->connection, new StaticSystemConfigService([]));
    }

    private function createTransferService(): TicketTransferService
    {
        return new TicketTransferService(
            $this->connection,
            new QrCodeGenerator(),
            self::container()->get(NumberRangeValueGeneratorInterface::class),
            new TokenCipher('resale-test-secret'),
        );
    }

    private function createScanService(): TicketScanService
    {
        return new TicketScanService(
            $this->connection,
            self::container()->get('fib_booking_scan_log.repository'),
            new StaticSystemConfigService([]),
            new RotatingScanVerifier(new RotatingCodeService(), new TokenCipher('scan-test-secret')),
            new ScanVerdictResolver($this->connection, self::container()->get('fib_booking_ticket.repository')),
        );
    }

    private function seedFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, seating_mode, active, created_at)
                VALUES (:id, 'Resale Test Hall', 'fib_test_resale_hall', 10, 'pool', 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->createOwnerCustomer();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, customer_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :customerId, 'B-RESALE-TEST',
                DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY) + INTERVAL 2 HOUR,
                1, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'customerId' => Uuid::fromHexToBytes(self::OWNER_ID),
            ],
        );

        $this->scanToken = bin2hex(random_bytes(32));
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, issued_at,
                entry_policy, seat_label, created_at)
                VALUES (:id, :reservationId, 'T-RESALE-1', :tokenHash, 'sent', NOW(3), 'multi', 'F7', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::TICKET_ID),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'tokenHash' => hash('sha256', $this->scanToken),
            ],
        );
    }

    /**
     * reservation.customer_id carries a real FK — the owner must exist.
     * Minimal DAL create against whatever storefront channel/country the
     * installation provides.
     */
    private function createOwnerCustomer(): void
    {
        $channel = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, LOWER(HEX(customer_group_id)) AS group_id, LOWER(HEX(language_id)) AS language_id
                FROM sales_channel WHERE type_id = UNHEX('8A243080F92E4C719546314B577CF82B') LIMIT 1
            SQL,
        );
        $countryId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(id)) FROM country WHERE active = 1 LIMIT 1
            SQL,
        );
        $salutationId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(id)) FROM salutation LIMIT 1
            SQL,
        );

        static::assertIsArray($channel);
        static::assertIsString($countryId);
        static::assertIsString($salutationId);

        self::container()->get('customer.repository')->create([
            [
                'id' => self::OWNER_ID,
                'customerNumber' => 'RESALE-OWNER-1',
                'salesChannelId' => $channel['id'],
                'languageId' => $channel['language_id'],
                'groupId' => $channel['group_id'],
                'salutationId' => $salutationId,
                'firstName' => 'Resale',
                'lastName' => 'Owner',
                'email' => 'resale-owner@example.invalid',
                'defaultBillingAddress' => [
                    'id' => Uuid::randomHex(),
                    'salutationId' => $salutationId,
                    'firstName' => 'Resale',
                    'lastName' => 'Owner',
                    'street' => 'Teststr. 1',
                    'zipcode' => '00000',
                    'city' => 'Testcity',
                    'countryId' => $countryId,
                ],
                'defaultShippingAddress' => [
                    'id' => Uuid::randomHex(),
                    'salutationId' => $salutationId,
                    'firstName' => 'Resale',
                    'lastName' => 'Owner',
                    'street' => 'Teststr. 1',
                    'zipcode' => '00000',
                    'city' => 'Testcity',
                    'countryId' => $countryId,
                ],
            ],
        ], $this->context);
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE listing FROM fib_booking_listing listing
                INNER JOIN fib_booking_ticket ticket ON ticket.id = listing.ticket_id
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-RESALE-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_scan_log WHERE source = 'phpunit' AND scanned_by = 'resale-test'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE ticket FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-RESALE-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number = 'B-RESALE-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_resale_hall'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM customer WHERE customer_number = 'RESALE-OWNER-1'
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
