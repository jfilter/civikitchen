import { test, expect } from '@playwright/test';

// E2E smoke tests for the standalone dev image. Boots via the example compose
// stack (or any reachable CIVICRM_BASE_URL) and verifies the auto-installed
// site renders.
//
// What this catches that the in-image functional tests can't:
//   - cv core:install ran but produced an unwriteable cache dir (see commit
//     introducing `runuser -u www-data`) — the page renders, but every
//     request drops a permission-error banner into the response
//   - Apache + PHP wired up but Civi assets 404 (SITE_URL mismatch)
//   - The Angular crmLogin bundle fails to boot — login form never appears
//   - settings.php missing/broken so /civicrm/* hits a 500

test.describe('standalone CiviCRM site', () => {
  test('homepage redirects anonymous users to login', async ({ page }) => {
    // Upstream CiviCRM's standaloneusers extension sends /civicrm/home to a
    // 403 for unauthenticated users instead of redirecting to login — bad UX
    // for a freshly-installed dev image. The image patches this with an
    // Apache RedirectMatch in /etc/apache2/conf-enabled/civicrm.conf. This
    // test guards that fix.
    await page.goto('/');
    expect(page.url(), 'should land on /civicrm/login').toMatch(/\/civicrm\/login/);
  });

  test('login page renders without permission errors', async ({ page }) => {
    await page.goto('/civicrm/login');

    // Title is set early in the template — confirms civi booted, settings
    // loaded, and the standaloneusers extension's login route resolved.
    await expect(page).toHaveTitle(/Log In/i);

    // Regression guard: this exact banner appears on every request when the
    // private/cache dir was created with the wrong owner (root vs www-data).
    // It's still a 200, the test would pass on status-code-only assertions.
    const html = await page.content();
    expect(html, 'cache dir should be writable').not.toContain(
      'could not write'
    );

    // The login form is a CiviCRM Angular module (crmLogin) that renders the
    // username + password fields with ARIA labels rather than name= attrs.
    // If the asset bundle never loads, this times out — exactly the
    // regression a static curl-check would miss.
    await expect(page.getByRole('textbox', { name: 'Username' })).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('static assets load (no 404s in console)', async ({ page }) => {
    const failures: string[] = [];
    page.on('response', (resp) => {
      if (resp.status() >= 400 && /\.(css|js)(\?|$)/.test(resp.url())) {
        failures.push(`${resp.status()} ${resp.url()}`);
      }
    });
    await page.goto('/civicrm/login');
    await page.waitForLoadState('networkidle');
    expect(failures, 'no CSS/JS asset 404s').toEqual([]);
  });
});
