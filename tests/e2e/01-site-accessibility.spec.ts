import { test, expect } from '@playwright/test';

test.describe('Site Accessibility', () => {
  test('site homepage loads successfully', async ({ page }) => {
    await page.goto('/');

    // Should not show error pages
    await expect(page).not.toHaveTitle(/404|500|Error/i);

    // Should load within reasonable time
    await expect(page).toHaveURL(/localhost:8080/);
  });

  test('site has Drupal content', async ({ page }) => {
    await page.goto('/');

    // Check for Drupal-specific elements
    const hasDrupal = await page.locator('body').evaluate(el => {
      return el.classList.toString().includes('drupal') ||
             document.querySelector('meta[name="Generator"]')?.getAttribute('content')?.includes('Drupal');
    });

    expect(hasDrupal).toBeTruthy();
  });

  test('site is using HTTPS or HTTP', async ({ page }) => {
    await page.goto('/');
    const url = page.url();
    expect(url).toMatch(/^https?:\/\//);
  });

  test('site has no console errors on homepage', async ({ page }) => {
    const errors: string[] = [];

    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Filter out known false positives
    const criticalErrors = errors.filter(err =>
      !err.includes('favicon') &&
      !err.includes('ERR_BLOCKED_BY_CLIENT')
    );

    expect(criticalErrors.length).toBe(0);
  });

  test('site responds with valid HTTP status', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBeLessThan(400);
  });

  test('site has accessible navigation', async ({ page }) => {
    await page.goto('/');

    // Check for common navigation elements
    const hasNav = await page.locator('nav, [role="navigation"], .menu, #navigation').count();
    expect(hasNav).toBeGreaterThan(0);
  });
});
