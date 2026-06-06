<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Extension;

use FibBookingSystem\Extension\Content\Product\ProductBookingConfigExtension;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductBookingConfigExtensionTest extends TestCase
{
    public function testExtendsProductWithBookingConfigAssociation(): void
    {
        $extension = new ProductBookingConfigExtension();
        $fields = new FieldCollection();

        $extension->extendFields($fields);
        $field = null;

        foreach ($fields as $candidate) {
            if ($candidate->getPropertyName() === ProductBookingConfigExtension::EXTENSION_NAME) {
                $field = $candidate;
                break;
            }
        }

        static::assertSame(ProductDefinition::class, $extension->getDefinitionClass());
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
    }
}
