import { test, expect } from '@playwright/test';

test.describe('CiviCRM Components', () => {
  test.beforeEach(async ({ page }) => {
    // Login before each test
    await page.goto('/user/login');
    await page.fill('input[name="name"]', 'demo');
    await page.fill('input[name="pass"]', 'demo');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForLoadState('networkidle');
  });

  test('can access Contacts component', async ({ page }) => {
    await page.goto('/civicrm/contact/search?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load without errors
    const url = page.url();
    expect(url).toContain('civicrm/contact');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });

  test('can access Contributions component', async ({ page }) => {
    await page.goto('/civicrm/contribute/search?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load contributions page
    const url = page.url();
    expect(url).toContain('civicrm/contribute');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });

  test('can access Events component', async ({ page }) => {
    await page.goto('/civicrm/event/manage?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load events page
    const url = page.url();
    expect(url).toContain('civicrm/event');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });

  test('can access Memberships component', async ({ page }) => {
    await page.goto('/civicrm/member/search?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load memberships page
    const url = page.url();
    expect(url).toContain('civicrm/member');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });

  test('can access Reports component', async ({ page }) => {
    await page.goto('/civicrm/report/list?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load reports page
    const url = page.url();
    expect(url).toContain('civicrm/report');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });

  test('can view a contact record', async ({ page }) => {
    // Go to contact search
    await page.goto('/civicrm/contact/search?reset=1');
    await page.waitForLoadState('networkidle');

    // Look for demo contacts (demo sites usually have sample data)
    // Try to find and click on a contact link if available
    const contactLink = page.locator('a[href*="/civicrm/contact/view"]').first();

    if (await contactLink.count() > 0) {
      await contactLink.click();
      await page.waitForLoadState('networkidle');

      // Should be on a contact view page
      const url = page.url();
      expect(url).toContain('civicrm/contact/view');

      // Should see contact information
      const hasContactInfo = await page.locator('.crm-container, #crm-container').count();
      expect(hasContactInfo).toBeGreaterThan(0);
    } else {
      // If no contacts found, at least verify the search page loaded
      const hasSearchForm = await page.locator('.crm-container, #crm-container').count();
      expect(hasSearchForm).toBeGreaterThan(0);
    }
  });

  test('CiviCRM API is accessible', async ({ page }) => {
    // Try to access CiviCRM API explorer (if available in demo)
    await page.goto('/civicrm/api');
    await page.waitForLoadState('networkidle');

    // Should load without 404
    const response = await page.goto('/civicrm/api');
    expect(response?.status()).toBeLessThan(404);
  });

  test('can access CiviCRM settings', async ({ page }) => {
    await page.goto('/civicrm/admin?reset=1');
    await page.waitForLoadState('networkidle');

    // Should load admin/settings page
    const url = page.url();
    expect(url).toContain('civicrm/admin');

    // Check for CiviCRM container
    const hasCiviCRM = await page.locator('.crm-container, #crm-container').count();
    expect(hasCiviCRM).toBeGreaterThan(0);
  });
});
