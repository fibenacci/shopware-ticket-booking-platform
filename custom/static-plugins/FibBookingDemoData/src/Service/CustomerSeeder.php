<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Seeds storefront-login-capable demo customers (buyer/seller pair for the
 * resale market, account-area testing, checkout). Existing customers are
 * matched by customer number so re-seeding never duplicates — and never
 * resets a password somebody changed.
 */
class CustomerSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Customer\CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly ShopLookups $lookups,
    ) {
    }

    /**
     * @param list<SeedSection> $customers
     *
     * @return array<string, array{id: string, email: string, customerNumber: string}> keyed by seed key
     */
    public function seedCustomers(
        array $customers,
        Context $context,
    ): array {
        if ($customers === []) {
            return [];
        }

        $channel = $this->lookups->storefrontChannel($context);
        $countryId = $this->lookups->countryId($context);
        $salutationId = $this->lookups->salutationId($context);

        $result = [];

        foreach ($customers as $customer) {
            $customerNumber = $customer->string('customerNumber');

            $existingId = $this->customerRepository->searchIds(
                (new Criteria())->addFilter(new EqualsFilter('customerNumber', $customerNumber)),
                $context,
            )->firstId();

            $customerId = is_string($existingId) ? $existingId : SeedIds::stable('customer:' . $customer->string('key'));

            $payload = [
                'id' => $customerId,
                'customerNumber' => $customerNumber,
                'salesChannelId' => $channel['id'],
                'languageId' => $channel['languageId'],
                'groupId' => $channel['groupId'],
                'salutationId' => $salutationId,
                'firstName' => $customer->string('firstName'),
                'lastName' => $customer->string('lastName'),
                'email' => $customer->string('email'),
                'active' => true,
            ];

            // Credentials and addresses only on first creation: re-seeding
            // must never reset a password or duplicate addresses.
            if ($existingId === null) {
                $address = [
                    'id' => SeedIds::stable('customer-address:' . $customer->string('key')),
                    'salutationId' => $salutationId,
                    'firstName' => $customer->string('firstName'),
                    'lastName' => $customer->string('lastName'),
                    'street' => $customer->string('street', 'Demo Street 1'),
                    'zipcode' => $customer->string('zipcode', '00000'),
                    'city' => $customer->string('city', 'Demo City'),
                    'countryId' => $countryId,
                ];

                $payload['password'] = $customer->string('password');
                $payload['defaultBillingAddress'] = $address;
                $payload['defaultShippingAddress'] = ['id' => SeedIds::stable('customer-address-shipping:' . $customer->string('key'))] + $address;
            }

            $this->customerRepository->upsert([$payload], $context);

            $result[$customer->string('key')] = [
                'id' => $customerId,
                'email' => $customer->string('email'),
                'customerNumber' => $customerNumber,
            ];
        }

        return $result;
    }
}
