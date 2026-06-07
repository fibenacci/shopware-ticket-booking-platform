<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use RuntimeException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Shared installation lookups for the seeders: the storefront sales channel
 * and the first country/salutation. Every lookup fails loudly when the
 * installation is incomplete — seeding into a half-installed shop would only
 * produce confusing follow-up errors.
 */
class ShopLookups
{
    /**
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<\Shopware\Core\System\Country\CountryCollection>           $countryRepository
     * @param EntityRepository<\Shopware\Core\System\Salutation\SalutationCollection>     $salutationRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $salutationRepository,
    ) {
    }

    /**
     * @return array{id: string, languageId: string, groupId: string}
     */
    public function storefrontChannel(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT))
            ->setLimit(1);

        $channel = $this->salesChannelRepository->search($criteria, $context)->getEntities()->first();

        if ($channel === null) {
            throw new RuntimeException('No storefront sales channel found — is Shopware fully installed?');
        }

        return [
            'id' => $channel->getId(),
            'languageId' => $channel->getLanguageId(),
            'groupId' => $channel->getCustomerGroupId(),
        ];
    }

    public function countryId(Context $context): string
    {
        $id = $this->countryRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();

        if (!is_string($id)) {
            throw new RuntimeException('No country entity found — is Shopware fully installed?');
        }

        return $id;
    }

    public function salutationId(Context $context): string
    {
        $id = $this->salutationRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();

        if (!is_string($id)) {
            throw new RuntimeException('No salutation entity found — is Shopware fully installed?');
        }

        return $id;
    }
}
