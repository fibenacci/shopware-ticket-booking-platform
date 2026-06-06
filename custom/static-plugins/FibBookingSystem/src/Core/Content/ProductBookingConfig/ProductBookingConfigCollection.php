<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\ProductBookingConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProductBookingConfigEntity>
 */
class ProductBookingConfigCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProductBookingConfigEntity::class;
    }
}
