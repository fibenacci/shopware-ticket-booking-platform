<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Resale;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Checkout\Cart\Resale\ResaleCartProcessor;
use FibBookingSystem\Checkout\Cart\Resale\ResaleLineItemFactory;
use FibBookingSystem\Core\Domain\Resale\BookingResaleService;
use FibBookingSystem\Core\Domain\Resale\ListingCartReader;
use FibBookingSystem\Core\Domain\Resale\ListingMode;
use FibBookingSystem\Core\Domain\Resale\ResaleSettlementService;
use FibBookingSystem\Core\Domain\Resale\TicketTransferService;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Generator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resale phase 2 — the fixed-price flow (docs/RESELL_PLAN.md):
 * cart pricing/eligibility from the listing row, settlement on payment with
 * ticket-level ownership transfer, and the refund unwind. The legal framing
 * shows up as assertions: zero fee (sold price == paid price) and tax-free
 * line items (private C2C).
 */
class ResaleFixedPriceFlowTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000f101';
    private const RESERVATION_ID = 'f1b0000000000000000000000000f102';
    private const TICKET_ID = 'f1b0000000000000000000000000f103';
    private const SELLER_ID = 'f1b0000000000000000000000000f1c1';
    private const BUYER_ID = 'f1b0000000000000000000000000f1c2';
    private const ASK_PRICE = 60.0;

    private Connection $connection;
    private Context $context;
    private string $scanToken;

    /**
     * @var list<string>
     */
    private array $orderIds = [];

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->context = Context::createDefaultContext();

        $this->cleanupFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if (getenv('FIB_DEBUG_KEEP') !== '1') {
            $this->cleanupFixtures();
        }
    }

    public function testPaidOrderSettlesTheListingAndMovesOwnershipToTheBuyer(): void
    {
        $listingId = $this->createListing();
        $orderId = $this->seedResaleOrder($listingId);

        $settled = $this->createSettlementService()->settleForOrder($orderId, $this->context);
        static::assertSame(1, $settled);

        // Listing snapshot: sold, guard released, FULL price to the seller
        // (no fee field anywhere — the platform earns nothing, by design).
        $listing = $this->fetchListingRow($listingId);
        static::assertSame('sold', $listing['status']);
        static::assertNull($listing['active_ticket_id']);
        static::assertSame(self::BUYER_ID, $listing['sold_to_customer_id']);
        static::assertTrue(FloatComparator::equals(self::ASK_PRICE, (float) $listing['sold_price']));
        static::assertSame(strtolower($orderId), $listing['sold_order_id']);
        static::assertIsString($listing['sold_ticket_id']);

        // Seller's copy is dead, the buyer's replacement carries the
        // ownership override — the reservation (capacity) is untouched.
        static::assertSame('revoked', $this->fetchTicketStatus(self::TICKET_ID));
        $newTicket = $this->fetchTicketRow($listing['sold_ticket_id']);
        static::assertSame('issued', $newTicket['status']);
        static::assertSame(self::BUYER_ID, $newTicket['owner_customer_id']);
        static::assertSame(self::TICKET_ID, $newTicket['replaced_ticket_id']);

        $reservation = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(customer_id)) AS customer_id, status, quantity
                FROM fib_booking_reservation WHERE id = :id
            SQL,
            ['id' => Uuid::fromHexToBytes(self::RESERVATION_ID)],
        );
        static::assertIsArray($reservation);
        static::assertSame(self::SELLER_ID, $reservation['customer_id'], 'capacity booking stays with the seller reservation');
        static::assertSame('confirmed', $reservation['status']);
        static::assertSame(1, (int) $reservation['quantity']);

        // Replayed payment event — idempotent, nothing settles twice.
        static::assertSame(0, $this->createSettlementService()->settleForOrder($orderId, $this->context));
    }

    public function testSettlementConflictLeavesACancelledListingUntouched(): void
    {
        $listingId = $this->createListing();
        $orderId = $this->seedResaleOrder($listingId);

        // Seller cancelled between checkout and payment.
        $this->createResaleService()->cancelListing($listingId, self::SELLER_ID);

        static::assertSame(0, $this->createSettlementService()->settleForOrder($orderId, $this->context));
        static::assertSame('cancelled', $this->fetchListingRow($listingId)['status']);
        static::assertSame('sent', $this->fetchTicketStatus(self::TICKET_ID), 'the conflict must not touch the ticket');
    }

    public function testRefundRevokesTheBuyersReplacementTicket(): void
    {
        $listingId = $this->createListing();
        $orderId = $this->seedResaleOrder($listingId);

        $service = $this->createSettlementService();
        $service->settleForOrder($orderId, $this->context);
        $soldTicketId = $this->fetchListingRow($listingId)['sold_ticket_id'];
        static::assertIsString($soldTicketId);

        static::assertSame(1, $service->revokeForOrder($orderId, 'phpunit refund'));
        static::assertSame('revoked', $this->fetchTicketStatus($soldTicketId));

        // Replays must not find anything left to revoke.
        static::assertSame(0, $service->revokeForOrder($orderId, 'phpunit refund'));
    }

    public function testCartProcessorPricesActiveListingsTaxFreeAtAskPrice(): void
    {
        $listingId = $this->createListing();

        $cart = $this->calculatedCartFor($listingId, self::BUYER_ID);

        static::assertCount(1, $cart->getLineItems());
        $lineItem = $cart->getLineItems()->first();
        static::assertNotNull($lineItem);
        $price = $lineItem->getPrice();
        static::assertNotNull($price);
        static::assertTrue(FloatComparator::equals(self::ASK_PRICE, $price->getTotalPrice()));
        static::assertCount(0, $price->getCalculatedTaxes(), 'private C2C sale is tax-free');
        static::assertStringContainsString('T-RESALE-FLOW', (string) $lineItem->getLabel());
        static::assertSame($listingId, $lineItem->getPayloadValue('fibResale')['listingId'] ?? null);
    }

    public function testCartProcessorDropsOwnListingsAndCancelledListings(): void
    {
        $listingId = $this->createListing();

        // The seller cannot buy their own lot.
        $ownCart = $this->calculatedCartFor($listingId, self::SELLER_ID);
        static::assertCount(0, $ownCart->getLineItems());
        static::assertTrue($ownCart->getErrors()->count() > 0);
        static::assertStringContainsString('own-listing', (string) $ownCart->getErrors()->first()?->getId());

        // A cancelled listing drops out of any cart.
        $this->createResaleService()->cancelListing($listingId, self::SELLER_ID);
        $goneCart = $this->calculatedCartFor($listingId, self::BUYER_ID);
        static::assertCount(0, $goneCart->getLineItems());
        static::assertStringContainsString('gone', (string) $goneCart->getErrors()->first()?->getId());
    }

    public function testCartProcessorRequiresANonGuestCustomer(): void
    {
        $listingId = $this->createListing();

        // Guest checkout: present, but not an account — C2C requires one.
        $cart = $this->calculatedCartFor($listingId, self::BUYER_ID, guest: true);

        static::assertCount(0, $cart->getLineItems());
        static::assertStringContainsString('login-required', (string) $cart->getErrors()->first()?->getId());
    }

    private function calculatedCartFor(
        string $listingId,
        ?string $customerId,
        bool $guest = false,
    ): Cart {
        $factory = new ResaleLineItemFactory();
        $processor = new ResaleCartProcessor(
            new ListingCartReader($this->connection),
            self::container()->get(QuantityPriceCalculator::class),
        );

        $customer = null;
        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
            $customer->setGuest($guest);
        }

        $salesChannelContext = Generator::generateSalesChannelContext(customer: $customer);

        $original = new Cart('fib-resale-test');
        $original->add($factory->create(['referencedId' => $listingId], $salesChannelContext));

        $data = new CartDataCollection();
        $behavior = new CartBehavior();
        $calculated = new Cart('fib-resale-test-calculated');

        $processor->collect($data, $original, $salesChannelContext, $behavior);
        $processor->process($data, $original, $calculated, $salesChannelContext, $behavior);

        return $calculated;
    }

    private function createListing(): string
    {
        return $this->createResaleService()->createListing(
            self::TICKET_ID,
            self::SELLER_ID,
            ListingMode::FIXED_PRICE,
            self::ASK_PRICE,
        );
    }

    /**
     * Minimal buyer order carrying ONE `fib-resale` line item — exactly what
     * the cart produces at checkout (referencedId = listing id, payload with
     * the listing reference, tax-free price).
     */
    private function seedResaleOrder(string $listingId): string
    {
        $orderId = Uuid::randomHex();
        $this->orderIds[] = $orderId;
        $addressId = Uuid::randomHex();

        $salesChannelId = $this->fetchHexId('SELECT LOWER(HEX(id)) FROM sales_channel WHERE active = 1 LIMIT 1');
        $countryId = $this->fetchHexId('SELECT LOWER(HEX(id)) FROM country WHERE active = 1 LIMIT 1');
        $salutationId = $this->fetchHexId('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');

        $rounding = json_decode(
            (string) json_encode(['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true], \JSON_THROW_ON_ERROR),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::container()->get('order.repository')->create([
            [
                'id' => $orderId,
                'orderNumber' => 'FIB-RESALE-' . Uuid::randomHex(),
                'salesChannelId' => $salesChannelId,
                'languageId' => Defaults::LANGUAGE_SYSTEM,
                'currencyId' => Defaults::CURRENCY,
                'currencyFactor' => 1.0,
                'orderDateTime' => '2026-06-01 10:00:00.000',
                'stateId' => $this->fetchHexId(
                    <<<'SQL'
                        SELECT LOWER(HEX(state.id)) FROM state_machine_state state
                        INNER JOIN state_machine machine ON machine.id = state.state_machine_id
                        WHERE machine.technical_name = 'order.state' AND state.technical_name = 'open'
                        SQL,
                ),
                'price' => new CartPrice(self::ASK_PRICE, self::ASK_PRICE, self::ASK_PRICE, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS),
                'shippingCosts' => new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()),
                'itemRounding' => $rounding,
                'totalRounding' => $rounding,
                'billingAddressId' => $addressId,
                'addresses' => [
                    [
                        'id' => $addressId,
                        'salutationId' => $salutationId,
                        'firstName' => 'Resale',
                        'lastName' => 'Buyer',
                        'street' => 'Teststr. 2',
                        'zipcode' => '00000',
                        'city' => 'Testcity',
                        'countryId' => $countryId,
                    ],
                ],
                'orderCustomer' => [
                    'customerId' => self::BUYER_ID,
                    'email' => 'resale-buyer@example.invalid',
                    'firstName' => 'Resale',
                    'lastName' => 'Buyer',
                    'salutationId' => $salutationId,
                    'customerNumber' => 'RESALE-BUYER-1',
                ],
                'lineItems' => [
                    [
                        'id' => Uuid::randomHex(),
                        'identifier' => $listingId,
                        'referencedId' => $listingId,
                        'type' => ResaleLineItemFactory::TYPE,
                        'label' => 'Resale ticket T-RESALE-FLOW',
                        'quantity' => 1,
                        'unitPrice' => self::ASK_PRICE,
                        'totalPrice' => self::ASK_PRICE,
                        'price' => new CalculatedPrice(self::ASK_PRICE, self::ASK_PRICE, new CalculatedTaxCollection(), new TaxRuleCollection()),
                        'payload' => ['fibResale' => ['listingId' => $listingId, 'ticketId' => self::TICKET_ID]],
                    ],
                ],
            ],
        ], $this->context);

        return $orderId;
    }

    private function createResaleService(): BookingResaleService
    {
        return new BookingResaleService(
            $this->connection,
            self::container()->get(SystemConfigService::class),
        );
    }

    private function createSettlementService(): ResaleSettlementService
    {
        return new ResaleSettlementService(
            $this->connection,
            new TicketTransferService(
                $this->connection,
                new QrCodeGenerator(),
                self::container()->get(NumberRangeValueGeneratorInterface::class),
                new TokenCipher('resale-flow-test-secret'),
            ),
            new NullLogger(),
        );
    }

    /**
     * @return array{status: string, active_ticket_id: string|null, sold_to_customer_id: string|null, sold_price: string|float|null, sold_order_id: string|null, sold_ticket_id: string|null}
     */
    private function fetchListingRow(string $listingId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT status, LOWER(HEX(active_ticket_id)) AS active_ticket_id,
                LOWER(HEX(sold_to_customer_id)) AS sold_to_customer_id, sold_price,
                LOWER(HEX(sold_order_id)) AS sold_order_id, LOWER(HEX(sold_ticket_id)) AS sold_ticket_id
                FROM fib_booking_listing WHERE id = :id
            SQL,
            ['id' => Uuid::fromHexToBytes($listingId)],
        );
        static::assertIsArray($row);

        /* @var array{status: string, active_ticket_id: string|null, sold_to_customer_id: string|null, sold_price: string|float|null, sold_order_id: string|null, sold_ticket_id: string|null} $row */
        return $row;
    }

    /**
     * @return array{status: string, owner_customer_id: string|null, replaced_ticket_id: string|null}
     */
    private function fetchTicketRow(string $ticketId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT status, LOWER(HEX(owner_customer_id)) AS owner_customer_id,
                LOWER(HEX(replaced_ticket_id)) AS replaced_ticket_id
                FROM fib_booking_ticket WHERE id = :id
            SQL,
            ['id' => Uuid::fromHexToBytes($ticketId)],
        );
        static::assertIsArray($row);

        /* @var array{status: string, owner_customer_id: string|null, replaced_ticket_id: string|null} $row */
        return $row;
    }

    private function fetchTicketStatus(string $ticketId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM fib_booking_ticket WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($ticketId)],
        );
        static::assertIsString($status);

        return $status;
    }

    private function seedFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, seating_mode, active, created_at)
                VALUES (:id, 'Resale Flow Hall', 'fib_test_resale_flow_hall', 10, 'pool', 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->createCustomer(self::SELLER_ID, 'RESALE-FLOW-SELLER', 'resale-flow-seller@example.invalid');
        $this->createCustomer(self::BUYER_ID, 'RESALE-FLOW-BUYER', 'resale-flow-buyer@example.invalid');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, customer_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :customerId, 'B-RESALE-FLOW',
                DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY) + INTERVAL 2 HOUR,
                1, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'customerId' => Uuid::fromHexToBytes(self::SELLER_ID),
            ],
        );

        $this->scanToken = bin2hex(random_bytes(32));
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, issued_at,
                entry_policy, seat_label, created_at)
                VALUES (:id, :reservationId, 'T-RESALE-FLOW', :tokenHash, 'sent', NOW(3), 'single', 'A1', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::TICKET_ID),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'tokenHash' => hash('sha256', $this->scanToken),
            ],
        );
    }

    private function createCustomer(
        string $customerId,
        string $customerNumber,
        string $email,
    ): void {
        $channel = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, LOWER(HEX(customer_group_id)) AS group_id, LOWER(HEX(language_id)) AS language_id
                FROM sales_channel WHERE type_id = UNHEX('8A243080F92E4C719546314B577CF82B') LIMIT 1
            SQL,
        );
        $countryId = $this->fetchHexId('SELECT LOWER(HEX(id)) FROM country WHERE active = 1 LIMIT 1');
        $salutationId = $this->fetchHexId('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');

        static::assertIsArray($channel);

        $address = [
            'id' => Uuid::randomHex(),
            'salutationId' => $salutationId,
            'firstName' => 'Resale',
            'lastName' => 'Flow',
            'street' => 'Teststr. 1',
            'zipcode' => '00000',
            'city' => 'Testcity',
            'countryId' => $countryId,
        ];

        self::container()->get('customer.repository')->create([
            [
                'id' => $customerId,
                'customerNumber' => $customerNumber,
                'salesChannelId' => $channel['id'],
                'languageId' => $channel['language_id'],
                'groupId' => $channel['group_id'],
                'salutationId' => $salutationId,
                'firstName' => 'Resale',
                'lastName' => 'Flow',
                'email' => $email,
                'defaultBillingAddress' => $address,
                'defaultShippingAddress' => ['id' => Uuid::randomHex()] + $address,
            ],
        ], $this->context);
    }

    private function fetchHexId(string $sql): string
    {
        $id = $this->connection->fetchOne($sql);
        static::assertIsString($id, sprintf('lookup failed: %s', $sql));

        return $id;
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE listing FROM fib_booking_listing listing
                INNER JOIN fib_booking_ticket ticket ON ticket.id = listing.ticket_id
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-RESALE-FLOW'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE ticket FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-RESALE-FLOW'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number = 'B-RESALE-FLOW'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_resale_flow_hall'
                SQL,
        );

        foreach ($this->orderIds as $orderId) {
            $orderIdBytes = Uuid::fromHexToBytes($orderId);
            $this->connection->executeStatement('DELETE FROM order_line_item WHERE order_id = :id', ['id' => $orderIdBytes]);
            $this->connection->executeStatement('DELETE FROM order_customer WHERE order_id = :id', ['id' => $orderIdBytes]);
            $this->connection->executeStatement('DELETE FROM order_address WHERE order_id = :id', ['id' => $orderIdBytes]);
            $this->connection->executeStatement('DELETE FROM `order` WHERE id = :id', ['id' => $orderIdBytes]);
        }
        $this->orderIds = [];

        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM customer WHERE customer_number IN ('RESALE-FLOW-SELLER', 'RESALE-FLOW-BUYER')
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
