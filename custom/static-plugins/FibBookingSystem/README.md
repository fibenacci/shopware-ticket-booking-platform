# FIB Booking System

Shopware 6 plugin providing a booking and reservation system.

The technical plan lives in [docs/ARCHITECTURE_PLAN.md](docs/ARCHITECTURE_PLAN.md).

Current state:

- dedicated plugin skeleton
- migration for resources, holds and reservations
- dedicated Shopware number ranges for bookings and tickets
- product entity extension for bookable products
- DAL definitions
- availability service
- transactional hold service
- scheduled task for expired holds
- storefront JSON endpoints for availability and hold creation
- storefront add-to-cart endpoint for booking holds
- Shopware-compliant storefront vanilla-JS plugin for bookable products
- ticket model with a secure QR scan token
- dedicated mail template type for QR ticket mails
- cart processor validating holds in the cart
- booking line item factory for bookable product line items
- order subscriber converting holds to reservations
- payment/order state subscriber for reservation confirmation and ticket creation
- admin API endpoint for ticket scanning at the desk
- no-store cache behaviour for booking JSON routes
- targeted cache tags and invalidation for booking resources and product booking configuration
- Flow Builder events for reservation confirmation and ticket issuance
- Flow Builder action `action.fib_booking.issue_ticket`
- Rule Builder rule `fibBookingLineItemInCart`
- PHPUnit unit and integration tests
- Playwright E2E test template for the storefront API flow

Tests (from the project root, see also `make test` / `make e2e`):

```bash
vendor/bin/phpunit -c custom/static-plugins/FibBookingSystem/phpunit.xml.dist
BASE_URL=http://booking.docker FIB_BOOKING_PRODUCT_ID=... FIB_BOOKING_RESOURCE_ID=... npx playwright test -c custom/static-plugins/FibBookingSystem/playwright.config.ts
```
