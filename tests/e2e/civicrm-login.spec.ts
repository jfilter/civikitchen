import { test, expect } from '@playwright/test';

// Verifies that the entrypoint's post-install demo-user step produced a
// usable login. Guards both demo-user.php (Contact + Email + User created
// with the admin role) and the entrypoint plumbing (env vars passed
// through `runuser -- env ... cv scr`).
//
// Credentials match examples/standalone/docker-compose.yml:
//   CIVIKITCHEN_DEMO_USER: admin    (no defaults set, so pass=admin too)
const DEMO_USER = process.env.DEMO_USER ?? 'admin';
const DEMO_PASS = process.env.DEMO_PASS ?? 'admin';
const TARGET = process.env.CIVIKITCHEN_E2E_TARGET ?? 'standalone';

test.describe('demo user login', () => {
  test.skip(TARGET !== 'standalone', 'Standalone demo-user login is image-specific');

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

    // The CiviCRM menubar renders ONLY once authenticated — it is absent on the
    // anonymous login page — so its presence is the version-stable "we logged in
    // and landed inside CiviCRM" marker. (Earlier the test asserted the demo
    // username as page text via the user menu, but CiviCRM 6.16's menubar no
    // longer prints the raw username anywhere in the page text, so that check
    // broke on every 6.16 build.)
    await expect(page.locator('#civicrm-menu')).toBeAttached({
      timeout: 15_000,
    });
  });
});
