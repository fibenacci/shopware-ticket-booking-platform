<?php

declare(strict_types=1);

namespace FibBookingSystem\Extension\Content\Product;

use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductBookingConfigExtension extends EntityExtension
{
    public const EXTENSION_NAME = 'fibBookingConfig';

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(self::EXTENSION_NAME, 'id', 'product_id', ProductBookingConfigDefinition::class, false))
                ->addFlags(new ApiAware(), new CascadeDelete())
        );
    }

    public function getDefinitionClass(): string
    {
        return ProductDefinition::class;
    }

    public function getEntityName(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }
}
