# Testing

This project includes comprehensive end-to-end (e2e) tests using Playwright to verify the CiviCRM installation.

## Prerequisites

Install Node.js dependencies:
```bash
npm install
```

Install Playwright browsers:
```bash
npx playwright install
```

## Running Tests

**Run all tests:**
```bash
npm test
```

**Run tests in UI mode (interactive):**
```bash
npm run test:ui
```

**Run tests in headed mode (see browser):**
```bash
npm run test:headed
```

**Debug tests:**
```bash
npm run test:debug
```

## Test Coverage

The test suite includes:

1. **Site Accessibility Tests** (`01-site-accessibility.spec.ts`)
   - Homepage loads successfully
   - Site has Drupal content
   - No console errors
   - Valid HTTP responses
   - Accessible navigation

2. **Login and CiviCRM Access Tests** (`02-login-civicrm.spec.ts`)
   - Login page accessibility
   - Demo user authentication (demo/demo)
   - CiviCRM dashboard access
   - CiviCRM menu availability

3. **CiviCRM Components Tests** (`03-civicrm-components.spec.ts`)
   - Contacts component
   - Contributions component
   - Events component
   - Memberships component
   - Reports component
   - API accessibility
   - Settings/admin pages

## Test Configuration

Tests are configured to:
- Wait up to 5 minutes for initial container startup and site creation
- Automatically start `docker-compose up -d` if containers aren't running
- Run on Chromium browser by default
- Capture screenshots on failure
- Record video on failure
- Generate HTML reports

To skip automatic Docker startup:
```bash
SKIP_WEBSERVER=1 npm test
```

To test against a different URL:
```bash
BASE_URL=http://localhost:8081 npm test
```

## Testing with Different PHP Versions

**Test with a specific PHP version:**
```bash
# Test with PHP 8.3
npm run test:php83

# Test with PHP 7.4
npm run test:php74
```

**Test all supported PHP versions:**
```bash
npm run test:all-php
```

This will:
1. Stop existing containers
2. Build with each PHP version (7.4, 8.0, 8.1, 8.2, 8.3)
3. Start containers and wait for site creation
4. Run the full test suite
5. Generate a summary report

**Manual testing with specific PHP version:**
```bash
# Start container with PHP 8.1
./scripts/test-with-php.sh 8.1

# Or with a different site type
./scripts/test-with-php.sh 8.1 wp-demo
```

**Available PHP test commands:**
- `npm run test:php74` - Test with PHP 7.4
- `npm run test:php80` - Test with PHP 8.0
- `npm run test:php81` - Test with PHP 8.1
- `npm run test:php82` - Test with PHP 8.2
- `npm run test:php83` - Test with PHP 8.3
- `npm run test:all-php` - Test all PHP versions sequentially

## Testing with Different CiviCRM Versions

**Test with a specific CiviCRM version:**
```bash
# Test CiviCRM 6.7.1 (latest stable)
npm run test:civicrm-6.7

# Test CiviCRM 6.6.3 (previous stable)
npm run test:civicrm-6.6

# Test CiviCRM 6.5.1 (older stable)
npm run test:civicrm-6.5

# Test CiviCRM master (development)
npm run test:civicrm-master
```

**Test all CiviCRM versions:**
```bash
npm run test:all-civicrm
```

This will test CiviCRM versions 6.5.1, 6.6.3, 6.7.1, and master with PHP 8.2 (default).

**Test with specific PHP + CiviCRM combination:**
```bash
# Test PHP 8.3 with CiviCRM 6.7.1
PHP_VERSION=8.3 CIVICRM_VERSION=6.7.1 npm run test:all-php

# Test PHP 8.1 with CiviCRM master
PHP_VERSION=8.1 CIVICRM_VERSION=master npm run test:all-civicrm
```

**Test all combinations (PHP × CiviCRM):**
```bash
npm run test:all-combinations
```

This will test all combinations of:
- PHP: 8.1, 8.2, 8.3
- CiviCRM: 6.5.1, 6.6.3, 6.7.1, master
- Total: 12 combinations

**Manual testing:**
```bash
# Test specific combination manually
CIVICRM_VERSION=6.7.1 ./scripts/test-with-php.sh 8.2
```

**Available CiviCRM test commands:**
- `npm run test:civicrm-6.5` - Test with CiviCRM 6.5.1
- `npm run test:civicrm-6.6` - Test with CiviCRM 6.6.3
- `npm run test:civicrm-6.7` - Test with CiviCRM 6.7.1
- `npm run test:civicrm-master` - Test with CiviCRM master
- `npm run test:all-civicrm` - Test all CiviCRM versions
- `npm run test:all-combinations` - Test all PHP × CiviCRM combinations

## CI/CD Integration

GitHub Actions workflows are included for automated testing:

### 1. Pull Request Testing (`.github/workflows/test-pr.yml`)
Runs on every PR and push to main:
- Tests stable combination: **PHP 8.2 + CiviCRM 6.7.1**
- Fast feedback (~10 minutes)
- Uploads test results as artifacts

### 2. Full Matrix Testing (`.github/workflows/test-combinations.yml`)
Tests all combinations:
- **Manual trigger:** Go to Actions → Test All Combinations → Run workflow
- **Weekly schedule:** Runs every Sunday at 2 AM UTC
- Tests all 12 combinations (PHP 8.1, 8.2, 8.3 × CiviCRM 6.5.1, 6.6.3, 6.7.1, master)
- Comprehensive compatibility verification

**View test results:**
1. Go to repository **Actions** tab
2. Select workflow run
3. Download test artifacts (screenshots, videos, reports)

## Test Scripts

The project includes several test scripts in the `scripts/` directory:

- **`scripts/test-php-versions.sh`** - Test all PHP versions with optional CiviCRM version
- **`scripts/test-civicrm-versions.sh`** - Test all CiviCRM versions with default PHP
- **`scripts/test-all-combinations.sh`** - Test all PHP × CiviCRM combinations
- **`scripts/test-with-php.sh`** - Quick manual testing with specific PHP version

See [tests/README.md](../tests/README.md) for detailed testing documentation.
