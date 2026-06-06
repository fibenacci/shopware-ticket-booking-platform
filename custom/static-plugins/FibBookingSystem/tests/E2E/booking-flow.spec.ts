import { expect, test } from '@playwright/test';

const productId = process.env.FIB_BOOKING_PRODUCT_ID;
const resourceId = process.env.FIB_BOOKING_RESOURCE_ID;

test.describe('FIB booking storefront flow', () => {
  test.skip(!productId || !resourceId, 'Set FIB_BOOKING_PRODUCT_ID and FIB_BOOKING_RESOURCE_ID to run this E2E test.');

  test('creates a hold and adds the booked product to the cart', async ({ request }) => {
    const startsAt = new Date(Date.now() + 24 * 60 * 60 * 1000);
    const endsAt = new Date(startsAt.getTime() + 60 * 60 * 1000);

    const holdResponse = await request.post('/fib-booking/hold', {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: {
        resourceId,
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
        quantity: 1,
      },
    });
    expect(holdResponse.ok()).toBeTruthy();

    const hold = await holdResponse.json();
    expect(hold.id).toBeTruthy();
    expect(hold.token).toBeTruthy();

    const cartResponse = await request.post('/fib-booking/cart/add', {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: {
        productId,
        holdId: hold.id,
        holdToken: hold.token,
        quantity: 1,
      },
    });
    expect(cartResponse.ok()).toBeTruthy();

    const cart = await cartResponse.json();
    expect(cart.success).toBe(true);
    expect(cart.lineItemId).toBeTruthy();
  });
});
