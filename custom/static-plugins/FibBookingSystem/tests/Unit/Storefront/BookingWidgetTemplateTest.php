<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;

/**
 * Slot products render the FULL booking calendar (shared include, bound to
 * the product) on their detail page — the legacy datetime-local widget is
 * gone for good. Pass products keep the validity info + start-date picker.
 */
class BookingWidgetTemplateTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views/storefront/';

    public function testBuyWidgetMountsTheProductBoundCalendarWithoutInlineScript(): void
    {
        $template = (string) file_get_contents(self::VIEWS . 'component/buy-widget/buy-widget-form.html.twig');

        static::assertStringContainsString('component/fib-booking/calendar-widget.html.twig', $template);
        static::assertStringContainsString('productId: product.id', $template);
        static::assertStringContainsString('product.extensions.fibBookingConfig', $template);
        static::assertStringContainsString('fibBookingValidityStart', $template, 'customer-anchored passes keep the start-date picker');

        // The legacy datetime-local widget must not resurface.
        static::assertStringNotContainsString('data-fib-booking-widget', $template);
        static::assertStringNotContainsString('datetime-local', $template);
        static::assertStringNotContainsString('product.customFields', $template);
        static::assertStringNotContainsString('<script>', $template);
    }

    public function testSharedCalendarWidgetCarriesPluginHookAndSeatPickerMount(): void
    {
        $template = (string) file_get_contents(self::VIEWS . 'component/fib-booking/calendar-widget.html.twig');

        static::assertStringContainsString('data-fib-booking-calendar="true"', $template);
        static::assertStringContainsString('data-fib-booking-calendar-options', $template);
        static::assertStringContainsString('fib-booking-calendar-seatpicker', $template);
        static::assertStringNotContainsString('<script>', $template);
    }
}
