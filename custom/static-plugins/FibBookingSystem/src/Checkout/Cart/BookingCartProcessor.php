<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Cart;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BookingCartProcessor implements CartProcessorInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($toCalculate->getLineItems()->getFlat() as $lineItem) {
            $bookingPayload = $this->extractBookingPayload($lineItem);

            if ($bookingPayload === null) {
                continue;
            }

            if ($this->isValidHold($bookingPayload['holdId'], $bookingPayload['holdToken'])) {
                continue;
            }

            $toCalculate->addErrors(new GenericCartError(
                sprintf('fib-booking-hold-invalid-%s', $lineItem->getId()),
                'fib-booking.hold-invalid',
                [
                    'lineItemId' => $lineItem->getId(),
                ],
                Error::LEVEL_ERROR,
                true,
                false,
                true,
            ));
        }
    }

    /**
     * @return array{holdId: string, holdToken: string}|null
     */
    private function extractBookingPayload(LineItem $lineItem): ?array
    {
        $payload = $lineItem->getPayloadValue('fibBooking');

        if (!is_array($payload)) {
            return null;
        }

        $holdId = $payload['holdId'] ?? null;
        $holdToken = $payload['holdToken'] ?? null;

        if (!is_string($holdId) || !is_string($holdToken) || $holdId === '' || $holdToken === '') {
            return null;
        }

        return [
            'holdId' => $holdId,
            'holdToken' => $holdToken,
        ];
    }

    private function isValidHold(string $holdId, string $holdToken): bool
    {
        if (!Uuid::isValid($holdId)) {
            return false;
        }

        $valid = $this->connection->fetchOne(
            "SELECT 1
             FROM fib_booking_hold
             WHERE id = :holdId
               AND token = :holdToken
               AND status = 'active'
               AND expires_at > UTC_TIMESTAMP(3)",
            [
                'holdId' => Uuid::fromHexToBytes($holdId),
                'holdToken' => $holdToken,
            ],
        );

        return $valid !== false;
    }
}
