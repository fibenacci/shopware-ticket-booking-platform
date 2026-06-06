import { expect, test } from '@playwright/test';

/**
 * Homepage smoke for the booking-calendar CMS element. Self-contained: the
 * resource id is read from the rendered element options, so no env vars are
 * needed (the demo data seeds the homepage layout).
 */
test.describe('FIB booking calendar on the homepage', () => {
  test('renders the calendar element and serves month data', async ({ page, request }) => {
    await page.goto('/');

    const calendar = page.locator('[data-fib-booking-calendar]');
    await expect(calendar).toHaveCount(1);

    const options = JSON.parse(await calendar.getAttribute('data-fib-booking-calendar-options') ?? '{}');
    expect(options.resourceId).toMatch(/^[0-9a-f]{32}$/);
    expect(Array.isArray(options.packages)).toBeTruthy();
    expect(options.packages.length).toBeGreaterThan(0);

    // The calendar JS renders the month grid client-side.
    await expect(page.locator('.fib-booking-calendar-grid .fib-booking-day').first()).toBeVisible();

    // Month endpoint returns bookable days for the configured resource.
    const month = new Date();
    const monthKey = `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`;
    const response = await request.post('/fib-booking/calendar', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      data: { resourceId: options.resourceId, month: monthKey },
    });
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(Array.isArray(data.days)).toBeTruthy();
    for (const day of data.days) {
      expect(['free', 'partial', 'full']).toContain(day.status);
    }
  });
});
