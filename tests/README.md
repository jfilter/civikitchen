# CiviCRM E2E Tests

This directory contains end-to-end tests for the CiviCRM Buildkit Docker setup using Playwright.

## Test Structure

```
tests/
└── e2e/
    ├── 01-site-accessibility.spec.ts    # Basic site accessibility tests
    ├── 02-login-civicrm.spec.ts         # Login and CiviCRM dashboard tests
    ├── 03-civicrm-components.spec.ts    # CiviCRM component tests
    └── helpers.ts                       # Shared test utilities
```

## Test Organization

Tests are numbered to run in a logical order:
1. **01-site-accessibility** - Verifies the site is accessible and loads correctly
2. **02-login-civicrm** - Tests authentication and CiviCRM dashboard access
3. **03-civicrm-components** - Tests individual CiviCRM components (Contacts, Events, etc.)

## Writing New Tests

### Basic Test Template

```typescript
import { test, expect } from '@playwright/test';

test.describe('Feature Name', () => {
  test('should do something', async ({ page }) => {
    await page.goto('/some-path');

    // Your test assertions
    await expect(page.locator('selector')).toBeVisible();
  });
});
```

### Using Helpers

```typescript
import { test, expect } from '@playwright/test';
import { loginAsDemoUser, navigateToCiviCRM } from './helpers';

test.describe('Feature requiring login', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsDemoUser(page);
  });

  test('can access some feature', async ({ page }) => {
    await navigateToCiviCRM(page, 'some/path');
    // Test logic here
  });
});
```

## Test Credentials

The demo site uses these credentials:
- **Username:** `demo`
- **Password:** `demo`

Admin credentials are generated during site creation and shown in the container logs.

## Common Selectors

- CiviCRM container: `.crm-container, #crm-container`
- Login form: `input[name="name"]`, `input[name="pass"]`
- Logout link: `text=/log out|sign out/i`

## Best Practices

1. **Use data-testid attributes** when possible for more stable selectors
2. **Wait for network idle** after navigation: `await page.waitForLoadState('networkidle')`
3. **Use helper functions** to avoid code duplication
4. **Group related tests** in `test.describe()` blocks
5. **Use beforeEach** for common setup like logging in
6. **Name tests descriptively** - start with "can" or "should"
7. **Keep tests independent** - don't rely on state from previous tests

## Debugging Failed Tests

1. **Run in headed mode** to see what's happening:
   ```bash
   npm run test:headed
   ```

2. **Use debug mode** to step through tests:
   ```bash
   npm run test:debug
   ```

3. **Check screenshots** in `test-results/` directory after failures

4. **Check videos** in `test-results/` directory after failures

5. **View HTML report** with detailed test results:
   ```bash
   npm run test:report
   ```

## Environment Variables

- `BASE_URL` - Base URL to test against (default: `http://localhost:8080`)
- `SKIP_WEBSERVER` - Set to `1` to skip automatic Docker startup

## CI/CD Integration

Tests are configured to run in CI with:
- 2 retries on failure
- Single worker (no parallelization)
- HTML and list reporters

Example GitHub Actions:
```yaml
- name: Install dependencies
  run: npm ci

- name: Install Playwright
  run: npx playwright install --with-deps

- name: Start Docker containers
  run: docker-compose up -d

- name: Run tests
  run: npm test

- name: Upload test results
  if: always()
  uses: actions/upload-artifact@v3
  with:
    name: playwright-report
    path: test-results/
```

## Testing Different PHP Versions

### Quick Start

**Test with a specific PHP version:**
```bash
npm run test:php83
```

**Test all PHP versions:**
```bash
npm run test:all-php
```

### How It Works

1. **scripts/test-php-versions.sh** - Automated script that:
   - Stops existing containers
   - Builds Docker image with each PHP version
   - Starts containers and waits for site ready
   - Runs full test suite
   - Generates pass/fail summary

2. **scripts/test-with-php.sh** - Manual testing script that:
   - Takes PHP version as argument
   - Builds and starts container
   - Follows logs for debugging

### Example Output

```bash
$ npm run test:all-php

==========================================
CiviCRM Multi-PHP Version Test Suite
==========================================

Testing PHP versions: 7.4 8.0 8.1 8.2 8.3
Site type: drupal10-demo

==========================================
Testing PHP 7.4
==========================================
Building with PHP 7.4...
✓ Site is accessible
Running Playwright tests...
✓ Tests passed for PHP 7.4

==========================================
Test Results Summary
==========================================
✓ PHP 7.4: PASSED
✓ PHP 8.0: PASSED
✓ PHP 8.1: PASSED
✓ PHP 8.2: PASSED
✓ PHP 8.3: PASSED
==========================================
```

### Available Commands

- `npm run test:php74` - Test with PHP 7.4
- `npm run test:php80` - Test with PHP 8.0
- `npm run test:php81` - Test with PHP 8.1
- `npm run test:php82` - Test with PHP 8.2 (default)
- `npm run test:php83` - Test with PHP 8.3
- `npm run test:all-php` - Test all versions

### Custom Site Types

Test with different CiviCRM site types:
```bash
# WordPress with PHP 8.3
CIVICRM_SITE_TYPE=wp-demo ./scripts/test-with-php.sh 8.3

# Standalone with PHP 8.1
CIVICRM_SITE_TYPE=standalone ./scripts/test-with-php.sh 8.1
```

### Troubleshooting

**Tests fail for specific PHP version:**
- Check compatibility: Some CiviCRM versions may not support all PHP versions
- Review build logs: `docker-compose logs civicrm`
- Try manually: `./scripts/test-with-php.sh 8.3` and debug

**Build takes too long:**
- Use `--no-cache` is intentional to ensure clean builds
- Each PHP version is tested in isolation
- Consider testing specific versions instead of all
