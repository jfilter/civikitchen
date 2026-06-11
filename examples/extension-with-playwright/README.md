# Playwright UI tests for a CiviCRM extension

A copy-pasteable starter: boot CiviCRM in Docker, run Playwright tests on
the host against `localhost:8080`. The login is handled once via a setup
project and shared across all tests.

## Try it

```bash
cd examples/extension-with-playwright
docker compose up -d                     # CiviCRM on http://localhost:8080
npm install
npx playwright install chromium
npm test                                 # runs the example tests
```

The example mounts `extensions/de.systopia.contract` so you can see green
tests immediately. Replace the volume in `docker-compose.yml` with your own
extension when you're ready (see comments in that file).

Useful variants:

```bash
npm run test:ui        # Playwright UI mode — best DX for writing tests
npm run test:headed    # watch the browser do its thing
npm run test:debug     # step through with Playwright Inspector
```

## Use it in your own extension

Copy these four files into your extension repo:

- `playwright.config.ts`
- `tests/auth.setup.ts`
- `tests/extension.spec.ts` — keep as a smoke test, add your own alongside
- `package.json` — or merge `devDependencies` and `scripts` into yours
- `.gitignore` — or append `node_modules/`, `.auth/`, `playwright-report/`,
  `test-results/` to yours

Then point `docker-compose.yml` (a copy of [`examples/standalone/`](../standalone/))
at your extension via the `volumes:` mapping, and you're set.

## How it works

`playwright.config.ts` defines two projects:

1. **setup** runs `tests/auth.setup.ts` once, logs in as the demo user, and
   writes the cookies/localStorage to `.auth/admin.json`.
2. **chromium** depends on `setup` and loads `.auth/admin.json` as
   `storageState`, so every test starts authenticated.

## Credentials

The `auth.setup.ts` step logs in with `admin` / `admin` by default — that's
the demo user the standalone image creates on first start (controlled by
`CIVIKITCHEN_DEMO_USER` / `CIVIKITCHEN_DEMO_PASS` in `docker-compose.yml`).

If you change the demo user, override at test time:

```bash
DEMO_USER=alice DEMO_PASS=secret npm test
```

## When to use Playwright vs. PHPUnit

- **Playwright** for UI flows: forms, modals, Angular/React widgets, JS
  behaviour, anything you'd otherwise test by clicking around.
- **PHPUnit (headless)** for API/business logic: APIv4 calls, hooks, BAOs,
  workflows. Faster, no browser, no compose stack required for unit tests.
  See the main [Extension development](../../docs/extension-development.md)
  guide for the headless setup.
