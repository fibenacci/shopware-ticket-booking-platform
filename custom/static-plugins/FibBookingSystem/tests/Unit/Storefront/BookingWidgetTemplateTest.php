<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;

class BookingWidgetTemplateTest extends TestCase
{
    public function testBookingWidgetTemplateUsesStorefrontPluginWithoutInlineScript(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig');

        static::assertStringContainsString('data-fib-booking-widget="true"', $template);
        static::assertStringContainsString('data-fib-booking-widget-options', $template);
        static::assertStringContainsString('product.extensions.fibBookingConfig', $template);
        static::assertStringNotContainsString('product.customFields', $template);
        static::assertStringNotContainsString('<script>', $template);
    }
}
