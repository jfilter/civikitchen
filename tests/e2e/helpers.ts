import { Page, ConsoleMessage } from '@playwright/test';

/**
 * Login helper function to avoid code duplication
 */
export async function loginAsDemoUser(page: Page): Promise<void> {
  await page.goto('/user/login');
  await page.fill('input[name="name"]', 'demo');
  await page.fill('input[name="pass"]', 'demo');
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}

/**
 * Check if user is logged in
 */
export async function isLoggedIn(page: Page): Promise<boolean> {
  return await page.locator('text=/log out|sign out/i').isVisible();
}

/**
 * Navigate to CiviCRM page and wait for it to load
 */
export async function navigateToCiviCRM(page: Page, path: string = ''): Promise<void> {
  const url = path ? `/civicrm/${path}` : '/civicrm';
  await page.goto(url);
  await page.waitForLoadState('networkidle');

  // Wait for CiviCRM container to be present
  await page.waitForSelector('.crm-container, #crm-container', { timeout: 10000 });
}

/**
 * Check if CiviCRM is loaded on the page
 */
export async function hasCiviCRMContent(page: Page): Promise<boolean> {
  const count = await page.locator('.crm-container, #crm-container').count();
  return count > 0;
}

/**
 * Get all console errors from the page
 */
export function captureConsoleErrors(page: Page): string[] {
  const errors: string[] = [];

  page.on('console', (msg: ConsoleMessage) => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });

  return errors;
}

/**
 * Filter out known non-critical console errors
 */
export function filterCriticalErrors(errors: string[]): string[] {
  return errors.filter(err =>
    !err.includes('favicon') &&
    !err.includes('ERR_BLOCKED_BY_CLIENT') &&
    !err.includes('net::ERR_BLOCKED_BY_ADBLOCKER')
  );
}
