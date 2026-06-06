<?php

declare(strict_types=1);

namespace FibBookingTheme;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

/**
 * Glassmorphism storefront theme (see src/Resources/theme.json):
 * dark premium look with an animated aurora background, frosted-glass
 * surfaces (backdrop-filter) and CSS-only motion. All effects degrade
 * gracefully (@supports fallbacks, prefers-reduced-motion).
 */
class FibBookingTheme extends Plugin implements ThemeInterface
{
}
