import { test, expect } from '@playwright/test';

test.describe('Login and CiviCRM Access', () => {
  test('can access login page', async ({ page }) => {
    await page.goto('/user/login');

    // Should see login form
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('input[name="pass"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
  });

  test('can login with demo user credentials', async ({ page }) => {
    await page.goto('/user/login');

    // Fill in demo credentials
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');

    // Submit login form
    await page.getByRole('button', { name: 'Log in' }).click();

    // Wait for navigation after login
    await page.waitForURL(/^((?!user\/login).)*$/, { timeout: 10000 });

    // Should be logged in - check for user menu or logout link
    const logoutLinks = await page.locator('text=/log out|sign out/i').count();
    expect(logoutLinks).toBeGreaterThan(0);
  });

  test('can access CiviCRM dashboard after login', async ({ page }) => {
    // Login first
    await page.goto('/user/login');
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForLoadState('networkidle');

    // Navigate to CiviCRM
    await page.goto('/civicrm');

    // Wait for CiviCRM to load
    await page.waitForLoadState('networkidle');

    // Check for CiviCRM elements
    const hasCiviCRM = await page.locator('body').evaluate(el => {
      return el.innerHTML.includes('CiviCRM') ||
             el.innerHTML.includes('civicrm-menu') ||
             document.title.includes('CiviCRM');
    });

    expect(hasCiviCRM).toBeTruthy();
  });

  test('CiviCRM dashboard displays key metrics', async ({ page }) => {
    // Login
    await page.goto('/user/login');
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForLoadState('networkidle');

    // Go to CiviCRM dashboard
    await page.goto('/civicrm/dashboard');
    await page.waitForLoadState('networkidle');

    // Should see dashboard content
    // CiviCRM dashboard typically has dashlets or statistics
    const hasDashboardContent = await page.locator('.crm-container, #crm-container, [id*="civicrm"]').count();
    expect(hasDashboardContent).toBeGreaterThan(0);
  });

  test('can access CiviCRM contacts', async ({ page }) => {
    // Login
    await page.goto('/user/login');
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForLoadState('networkidle');

    // Go to CiviCRM contacts
    await page.goto('/civicrm/contact/search?reset=1');
    await page.waitForLoadState('networkidle');

    // Should see contact search interface
    const hasContactSearch = await page.locator('.crm-container, #crm-container').count();
    expect(hasContactSearch).toBeGreaterThan(0);
  });

  test('CiviCRM menu is accessible', async ({ page }) => {
    // Login
    await page.goto('/user/login');
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForLoadState('networkidle');

    // Go to CiviCRM
    await page.goto('/civicrm');
    await page.waitForLoadState('networkidle');

    // Look for CiviCRM menu items (common in CiviCRM installations)
    // The menu structure can vary, but there should be navigation elements
    const bodyText = await page.locator('body').textContent();
    const hasMenu = bodyText && (
      bodyText.includes('Contact') ||
      bodyText.includes('Contribute') ||
      bodyText.includes('Event') ||
      bodyText.includes('Member') ||
      bodyText.includes('Report')
    );

    expect(hasMenu).toBeTruthy();
  });
});
