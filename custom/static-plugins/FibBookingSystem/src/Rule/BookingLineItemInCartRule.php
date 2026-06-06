<?php

declare(strict_types=1);

namespace FibBookingSystem\Rule;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleScope;

class BookingLineItemInCartRule extends Rule
{
    final public const RULE_NAME = 'fibBookingLineItemInCart';

    public function __construct()
    {
        parent::__construct();
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CartRuleScope) {
            return false;
        }

        foreach ($scope->getCart()->getLineItems()->getFlat() as $lineItem) {
            if ($this->isBookingLineItem($lineItem)) {
                return true;
            }
        }

        return false;
    }

    public function getConstraints(): array
    {
        return [];
    }

    private function isBookingLineItem(LineItem $lineItem): bool
    {
        $payload = $lineItem->getPayloadValue('fibBooking');

        return is_array($payload)
            && is_string($payload['holdId'] ?? null)
            && is_string($payload['holdToken'] ?? null);
    }
}
