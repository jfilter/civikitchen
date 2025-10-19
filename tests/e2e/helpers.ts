import { Page, ConsoleMessage } from '@playwright/test';
import { execSync } from 'child_process';

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
 * Login as admin user (password retrieved from logs)
 */
export async function loginAsAdminUser(page: Page): Promise<void> {
  // Get admin password from civibuild logs
  const password = getAdminPassword();

  await page.goto('/user/login');
  await page.fill('input[name="name"]', 'admin');
  await page.fill('input[name="pass"]', password);
  // Be more specific - find the login button specifically
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForLoadState('networkidle');
}

/**
 * Get admin password from civibuild show command
 */
export function getAdminPassword(): string {
  try {
    const output = execSync(
      'docker-compose exec -T civicrm bash -c "civibuild show site"',
      { encoding: 'utf-8', timeout: 10000 }
    );

    const match = output.match(/ADMIN_PASS:\s*(.+)/);
    if (match && match[1]) {
      return match[1].trim();
    }

    throw new Error('Could not find admin password in civibuild output');
  } catch (error) {
    console.error('Error getting admin password:', error);
    throw error;
  }
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

/**
 * Execute a command in the CiviCRM Docker container
 */
export function execDockerCommand(command: string, timeout: number = 30000): string {
  try {
    return execSync(
      `docker-compose exec -T civicrm bash -c "${command}"`,
      { encoding: 'utf-8', timeout }
    );
  } catch (error) {
    console.error(`Error executing Docker command: ${command}`, error);
    throw error;
  }
}

/**
 * Navigate to CiviCRM Extensions management page
 */
export async function navigateToExtensions(page: Page): Promise<void> {
  await page.goto('/civicrm/admin/extensions?reset=1');
  await page.waitForLoadState('networkidle');

  // Wait for extensions page to load
  await page.waitForSelector('.crm-container, #crm-container', { timeout: 10000 });
}
