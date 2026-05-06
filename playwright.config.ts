import { defineConfig, devices } from '@playwright/test';

// Smoke tests for the standalone dev image. Boot the example compose stack,
// wait for /civicrm/login, then run `npx playwright test`. Locally:
//
//   cd examples/standalone && docker compose up -d && cd ../..
//   npm install
//   npx playwright install chromium --with-deps
//   npx playwright test
//
// CI runs the same sequence in .github/workflows/build-dev-images.yml.

const BASE_URL = process.env.CIVICRM_BASE_URL ?? 'http://localhost:8080';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: devices['Desktop Chrome'] },
  ],
});
