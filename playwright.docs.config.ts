import { defineConfig, devices } from '@playwright/test';

// WCAG checks for the documentation site that `make docs` builds into site/.
// `make docs-a11y` builds it and runs tests/docs against a local server.

const PORT = 18432;

export default defineConfig({
  testDir: './tests/docs',
  timeout: 30_000,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
  },
  webServer: {
    command: `python3 -m http.server ${PORT} --bind 127.0.0.1 --directory site`,
    url: `http://127.0.0.1:${PORT}/`,
    reuseExistingServer: false,
    stderr: 'ignore',
  },
  projects: [
    { name: 'chromium', use: devices['Desktop Chrome'] },
  ],
});
