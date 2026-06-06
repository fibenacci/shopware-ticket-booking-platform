import template from './sw-product-detail.html.twig';

/**
 * Appends the booking tab to the product detail tab bar — the route itself
 * is registered via routeMiddleware in ../../index.js.
 */
Shopware.Component.override('sw-product-detail', {
    template,
});
