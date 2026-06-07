<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use DateInterval;
use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Resale\BookingResaleService;
use FibBookingSystem\Core\Domain\Resale\ListingMode;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\FibBookingException;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Seeds realistic purchases: a paid order per seed entry, the customer-bound
 * confirmed reservation referencing the order line item (so the anti-scalping
 * price cap has its reference price), and a QR ticket issued through the
 * regular ticket service. One seller ticket is put on the resale market —
 * `make up` boots into a browsable secondary market.
 *
 * Idempotent by unique keys (orderNumber / bookingNumber); the resale
 * listing tolerates "already listed" on re-runs.
 *
 * @phpstan-type OrderSummary array{orderNumber: string, bookingNumber: string, customer: string, ticketNumber: string|null, listed: bool}
 */
class OrderSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection>                                                $orderRepository
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection>                                             $productRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection>               $reservationRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection>                         $ticketRepository
     * @param EntityRepository<\Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection> $stateRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly EntityRepository $ticketRepository,
        private readonly EntityRepository $stateRepository,
        private readonly ShopLookups $lookups,
        private readonly BookingTicketService $ticketService,
        private readonly BookingResaleService $resaleService,
    ) {
    }

    /**
     * @param list<SeedSection>                                                       $orders
     * @param array<string, array{id: string, email: string, customerNumber: string}> $customers
     * @param array<string, string>                                                   $resourceIds
     *
     * @return list<OrderSummary>
     */
    public function seedOrders(
        array $orders,
        array $customers,
        array $resourceIds,
        Context $context,
    ): array {
        $result = [];

        foreach ($orders as $order) {
            $customer = $customers[$order->string('customer')]
                ?? throw new RuntimeException(sprintf('Order "%s" references unknown customer key "%s".', $order->string('orderNumber'), $order->string('customer')));
            $resourceId = $resourceIds[$order->string('resource')]
                ?? throw new RuntimeException(sprintf('Order "%s" references unknown resource key "%s".', $order->string('orderNumber'), $order->string('resource')));

            $result[] = $this->seedOrder($order, $customer, $resourceId, $context);
        }

        return $result;
    }

    /**
     * @param array{id: string, email: string, customerNumber: string} $customer
     *
     * @return OrderSummary
     */
    private function seedOrder(
        SeedSection $order,
        array $customer,
        string $resourceId,
        Context $context,
    ): array {
        $product = $this->fetchProduct($order->string('productNumber'), $context);
        $lineItemId = SeedIds::stable('order-line-item:' . $order->string('orderNumber'));

        $orderId = $this->upsertOrder($order, $customer, $product, $lineItemId, $context);
        $reservationId = $this->upsertReservation($order, $customer['id'], $resourceId, $orderId, $lineItemId, $context);

        [$ticketId, $ticketNumber] = $this->issueTicketOnce($reservationId, $context);

        $listed = false;
        $resale = $order->sectionOrNull('resale');
        if ($resale !== null && $ticketId !== null) {
            $listed = $this->listForResale($ticketId, $customer['id'], $resale->float('askPrice'), $context);
        }

        return [
            'orderNumber' => $order->string('orderNumber'),
            'bookingNumber' => $order->string('bookingNumber'),
            'customer' => $customer['email'],
            'ticketNumber' => $ticketNumber,
            'listed' => $listed,
        ];
    }

    /**
     * @param array{id: string, email: string, customerNumber: string}      $customer
     * @param array{id: string, name: string, price: float, number: string} $product
     */
    private function upsertOrder(
        SeedSection $order,
        array $customer,
        array $product,
        string $lineItemId,
        Context $context,
    ): string {
        $orderNumber = $order->string('orderNumber');

        $existingId = $this->orderRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('orderNumber', $orderNumber)),
            $context,
        )->firstId();

        if (is_string($existingId)) {
            return $existingId;
        }

        $orderId = SeedIds::stable('order:' . $orderNumber);
        $addressId = SeedIds::stable('order-address:' . $orderNumber);
        $quantity = max(1, $order->int('quantity', 1));
        $total = $product['price'] * $quantity;
        $salutationId = $this->lookups->salutationId($context);
        $countryId = $this->lookups->countryId($context);
        $rounding = ['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true];

        $this->orderRepository->create([
            [
                'id' => $orderId,
                'orderNumber' => $orderNumber,
                'salesChannelId' => $this->lookups->storefrontChannel($context)['id'],
                'languageId' => Defaults::LANGUAGE_SYSTEM,
                'currencyId' => Defaults::CURRENCY,
                'currencyFactor' => 1.0,
                'orderDateTime' => (new DateTimeImmutable('-1 day'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'stateId' => $this->fetchStateId('order.state', 'open', $context),
                'price' => new CartPrice($total, $total, $total, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS),
                'shippingCosts' => new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()),
                'itemRounding' => $rounding,
                'totalRounding' => $rounding,
                'billingAddressId' => $addressId,
                'addresses' => [
                    [
                        'id' => $addressId,
                        'salutationId' => $salutationId,
                        'firstName' => 'Demo',
                        'lastName' => 'Customer',
                        'street' => 'Demo Street 1',
                        'zipcode' => '00000',
                        'city' => 'Demo City',
                        'countryId' => $countryId,
                    ],
                ],
                'orderCustomer' => [
                    'customerId' => $customer['id'],
                    'email' => $customer['email'],
                    'firstName' => 'Demo',
                    'lastName' => 'Customer',
                    'salutationId' => $salutationId,
                    'customerNumber' => $customer['customerNumber'],
                ],
                'lineItems' => [
                    [
                        'id' => $lineItemId,
                        'identifier' => $product['id'],
                        'referencedId' => $product['id'],
                        'productId' => $product['id'],
                        'type' => 'product',
                        'label' => $product['name'],
                        'quantity' => $quantity,
                        'unitPrice' => $product['price'],
                        'totalPrice' => $total,
                        'price' => new CalculatedPrice($product['price'], $total, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity),
                        // productNumber is REQUIRED alongside productId by the
                        // order line item validator.
                        'payload' => ['productNumber' => $product['number'], 'demo' => true],
                    ],
                ],
            ],
        ], $context);

        return $orderId;
    }

    private function upsertReservation(
        SeedSection $order,
        string $customerId,
        string $resourceId,
        string $orderId,
        string $lineItemId,
        Context $context,
    ): string {
        $bookingNumber = $order->string('bookingNumber');

        $existingId = $this->reservationRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('bookingNumber', $bookingNumber)),
            $context,
        )->firstId();
        $reservationId = is_string($existingId) ? $existingId : SeedIds::stable('reservation:' . $bookingNumber);

        [$hour, $minute] = array_map(intval(...), explode(':', $order->string('startTime', '18:00')));
        $startsAt = (new DateTimeImmutable('today'))
            ->add(new DateInterval('P' . max(1, $order->int('startsAtOffsetDays', 1)) . 'D'))
            ->setTime($hour, $minute);
        $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', max(5, $order->int('durationMinutes', 120)))));

        $this->reservationRepository->upsert([
            [
                'id' => $reservationId,
                'resourceId' => $resourceId,
                'customerId' => $customerId,
                'orderId' => $orderId,
                'orderVersionId' => Defaults::LIVE_VERSION,
                'orderLineItemId' => $lineItemId,
                'orderLineItemVersionId' => Defaults::LIVE_VERSION,
                'bookingNumber' => $bookingNumber,
                'startsAt' => $startsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'endsAt' => $endsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'quantity' => max(1, $order->int('quantity', 1)),
                'status' => 'confirmed',
                'payload' => ['demo' => true],
            ],
        ], $context);

        return $reservationId;
    }

    /**
     * @return array{0: string|null, 1: string|null} live ticket id + number (number null on re-runs)
     */
    private function issueTicketOnce(
        string $reservationId,
        Context $context,
    ): array {
        try {
            $ticket = $this->ticketService->issueTicket($reservationId, $context, ['demo' => true]);

            return [$ticket->getId(), $ticket->getTicketNumber()];
        } catch (FibBookingException) {
            // Re-run: reuse the existing live ticket for the resale step.
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('reservationId', $reservationId));
            $criteria->addFilter(new EqualsAnyFilter('status', ['issued', 'sent']));
            $criteria->setLimit(1);

            $ticketId = $this->ticketRepository->searchIds($criteria, $context)->firstId();

            return [is_string($ticketId) ? $ticketId : null, null];
        }
    }

    private function listForResale(
        string $ticketId,
        string $sellerCustomerId,
        float $askPrice,
        Context $context,
    ): bool {
        try {
            $this->resaleService->createListing($ticketId, $sellerCustomerId, ListingMode::FIXED_PRICE, $askPrice);

            return true;
        } catch (FibBookingException) {
            // Already listed (re-run) or no longer listable — both fine for
            // demo data.
            return false;
        }
    }

    /**
     * @return array{id: string, name: string, price: float, number: string}
     */
    private function fetchProduct(
        string $productNumber,
        Context $context,
    ): array {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('productNumber', $productNumber));
        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        if ($product === null) {
            throw new RuntimeException(sprintf('Demo order references unknown product "%s" — seed the catalog first.', $productNumber));
        }

        $price = $product->getPrice()?->first()?->getGross() ?? 0.0;

        return [
            'id' => $product->getId(),
            'name' => (string) $product->getName(),
            'price' => $price,
            'number' => $productNumber,
        ];
    }

    private function fetchStateId(
        string $machine,
        string $state,
        Context $context,
    ): string {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('stateMachine.technicalName', $machine))
            ->addFilter(new EqualsFilter('technicalName', $state))
            ->setLimit(1);

        $id = $this->stateRepository->searchIds($criteria, $context)->firstId();

        if (!is_string($id)) {
            throw new RuntimeException(sprintf('State "%s/%s" not found.', $machine, $state));
        }

        return $id;
    }
}
