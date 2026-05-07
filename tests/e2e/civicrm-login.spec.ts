import { test, expect } from '@playwright/test';

// Verifies that the entrypoint's post-install demo-user step produced a
// usable login. Guards both demo-user.php (Contact + Email + User created
// with the admin role) and the entrypoint plumbing (env vars passed
// through `runuser -- env ... cv scr`).
//
// Credentials match examples/standalone/docker-compose.yml:
//   CIVICRM_DEMO_USER: admin    (no defaults set, so pass=admin too)
const DEMO_USER = process.env.DEMO_USER ?? 'admin';
const DEMO_PASS = process.env.DEMO_PASS ?? 'admin';

test.describe('demo user login', () => {
  test('admin/admin logs in and lands inside CiviCRM', async ({ page }) => {
    await page.goto('/civicrm/login');

    // crmLogin Angular module renders fields with ARIA labels (see
    // civicrm-smoke.spec.ts for the same pattern).
    await page.getByRole('textbox', { name: 'Username' }).fill(DEMO_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(DEMO_PASS);
    await page.getByRole('button', { name: /log in/i }).click();

    // After a successful login, standaloneusers redirects away from
    // /civicrm/login. The exact landing page depends on Civi's
    // post-login URL setting, so just assert we're no longer on the
    // login page and a known logged-in element renders.
    await expect(page).not.toHaveURL(/\/civicrm\/login/, { timeout: 15_000 });

    // The user menu (with the demo username) is the most stable
    // post-login marker — present on every Civi page once authenticated.
    await expect(page.locator('body')).toContainText(DEMO_USER, {
      timeout: 15_000,
    });
  });
});
