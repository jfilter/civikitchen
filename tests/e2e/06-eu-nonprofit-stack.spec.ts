import { test, expect } from '@playwright/test';
import {
  loginAsAdminUser,
  navigateToExtensions,
  getEnabledExtensions,
  isExtensionEnabled,
  navigateToCiviBanking,
  navigateToCiviSEPA,
  captureConsoleErrors,
  filterCriticalErrors
} from './helpers';

/**
 * EU Nonprofit Extension Stack Tests
 *
 * Tests the installation and functionality of the European nonprofit
 * extension stack including CiviBanking, CiviSEPA, and related extensions.
 *
 * Note: This test is skipped in CI due to bind mount restrictions.
 */

// Skip this test in CI environment (like tests 04 and 05)
const isCI = process.env.CI === 'true' || process.env.GITHUB_ACTIONS === 'true';

test.describe('EU Nonprofit Extension Stack', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin user before each test
    await loginAsAdminUser(page);
  });

  test('should have all EU nonprofit extensions installed', async () => {
    if (isCI) {
      test.skip();
      return;
    }

    const requiredExtensions = [
      'org.project60.banking',
      'org.project60.sepa',
      'de.systopia.contract',
      'de.systopia.twingle',
      'de.systopia.gdprx',
      'de.systopia.xcm',
      'de.systopia.identitytracker',
      'org.civicrm.shoreditch',
      'org.civicrm.contactlayout'
    ];

    const enabledExtensions = getEnabledExtensions();

    console.log('Enabled extensions:', enabledExtensions);

    for (const extensionKey of requiredExtensions) {
      const isEnabled = isExtensionEnabled(extensionKey);
      expect(isEnabled).toBe(true);
      console.log(`✓ ${extensionKey} is installed`);
    }
  });

  test('should be able to access CiviBanking admin page', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Check that CiviBanking is enabled
    expect(isExtensionEnabled('org.project60.banking')).toBe(true);

    // Capture console errors
    const errors = captureConsoleErrors(page);

    // Navigate to CiviBanking
    await navigateToCiviBanking(page);

    // Check that we're on the banking manager page
    await expect(page).toHaveURL(/\/civicrm\/banking\/manager/);

    // Verify CiviCRM container is present (banking page loaded)
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();

    // Check for any critical console errors
    const criticalErrors = filterCriticalErrors(errors);
    expect(criticalErrors.length).toBe(0);
  });

  test('should be able to access CiviSEPA settings page', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Check that CiviSEPA is enabled
    expect(isExtensionEnabled('org.project60.sepa')).toBe(true);

    // Capture console errors
    const errors = captureConsoleErrors(page);

    // Navigate to CiviSEPA
    await navigateToCiviSEPA(page);

    // Check that we're on the SEPA page
    await expect(page).toHaveURL(/\/civicrm\/sepa/);

    // Verify CiviCRM container is present (SEPA page loaded)
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();

    // Check for any critical console errors
    const criticalErrors = filterCriticalErrors(errors);
    expect(criticalErrors.length).toBe(0);
  });

  test('should be able to access Extensions management page', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Capture console errors
    const errors = captureConsoleErrors(page);

    // Navigate to Extensions page
    await navigateToExtensions(page);

    // Check that we're on the extensions page
    await expect(page).toHaveURL(/\/civicrm\/admin\/extensions/);

    // Verify CiviCRM container is present
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();

    // Verify that we can see extension information on the page
    // The page should contain text about extensions
    const pageContent = await page.textContent('body');
    expect(pageContent).toContain('Extension');

    // Check for any critical console errors
    const criticalErrors = filterCriticalErrors(errors);
    expect(criticalErrors.length).toBe(0);
  });

  test('should have Shoreditch theme available', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Check that Shoreditch is enabled
    expect(isExtensionEnabled('org.civicrm.shoreditch')).toBe(true);

    // Capture console errors
    const errors = captureConsoleErrors(page);

    // Navigate to Display Preferences to check theme
    await page.goto('/civicrm/admin/setting/preferences/display?reset=1');
    await page.waitForLoadState('networkidle');

    // Verify we're on the display preferences page
    await expect(page).toHaveURL(/\/civicrm\/admin\/setting\/preferences\/display/);

    // Verify CiviCRM container is present (use first() to handle multiple matches)
    await expect(page.locator('.crm-container, #crm-container').first()).toBeVisible();

    // Check for any critical console errors
    const criticalErrors = filterCriticalErrors(errors);
    expect(criticalErrors.length).toBe(0);
  });

  test('should have ContactLayout extension enabled', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Verify ContactLayout is enabled (critical dependency)
    expect(isExtensionEnabled('org.civicrm.contactlayout')).toBe(true);

    // Navigate to a contact page to verify ContactLayout is functional
    await page.goto('/civicrm/contact/view?reset=1&cid=1');
    await page.waitForLoadState('networkidle');

    // Verify we can access the contact page
    await expect(page).toHaveURL(/\/civicrm\/contact\/view/);

    // Verify CiviCRM container is present
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();
  });

  test('should have Contract extension with proper configuration', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Check that Contract extension is enabled
    expect(isExtensionEnabled('de.systopia.contract')).toBe(true);

    // Capture console errors
    const errors = captureConsoleErrors(page);

    // Try to access contract-related page
    await page.goto('/civicrm/admin/setting/preferences/contract?reset=1');
    await page.waitForLoadState('networkidle');

    // Verify CiviCRM container is present
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();

    // Check for any critical console errors
    const criticalErrors = filterCriticalErrors(errors);
    expect(criticalErrors.length).toBe(0);
  });

  test('should have XCM (Extended Contact Matcher) enabled', async ({ page }) => {
    if (isCI) {
      test.skip();
      return;
    }

    // Verify XCM is enabled
    expect(isExtensionEnabled('de.systopia.xcm')).toBe(true);

    // Navigate to XCM configuration page
    await page.goto('/civicrm/admin/setting/xcm?reset=1');
    await page.waitForLoadState('networkidle');

    // Verify we can access the XCM settings page
    await expect(page).toHaveURL(/\/civicrm\/admin\/setting\/xcm/);

    // Verify CiviCRM container is present
    await expect(page.locator('.crm-container, #crm-container')).toBeVisible();
  });
});
