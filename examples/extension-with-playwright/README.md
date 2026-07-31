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
npm run test:e2e                         # runs the example tests
```

The script is called `test:e2e`, not `test`, because that is the name the
shared `extension-ci.yml` runs when you switch its `playwright` input on —
and it leaves npm's `test` slot free for the JS unit suite that CI's
`js_tests` input runs. Two suites, two scripts, both reachable from CI.

The example mounts `extensions/de.systopia.contract` so you can see green
tests immediately. Replace the volume in `docker-compose.yml` with your own
extension when you're ready (see comments in that file).

Useful variants:

```bash
npm run test:ui        # Playwright UI mode — best DX for writing tests
npm run test:headed    # watch the browser do its thing
npm run test:debug     # step through with Playwright Inspector
```

## Run it in CI

Once the files below are in your extension repo, the shared workflow runs
this suite for you — no repo-local browser job:

```yaml
    with:
      key: myextension
      playwright: true
```

The job boots your `.docker/docker-compose.ci.yml` stack with port 8080
published and the `admin` / `admin` demo user created, then runs
`npm run test:e2e` on the runner with `CIVICRM_BASE_URL`, `DEMO_USER` and
`DEMO_PASS` set — the same variables `playwright.config.ts` and
`tests/auth.setup.ts` already read here. Report and traces are uploaded when
it fails. It is a slow check: put it in the scheduled caller, not in
`ci.yml`. See
[extension-standards.md](../../docs/extension-standards.md#frontend-npm-dependencies-js-tests-and-browser-tests).

## Use it in your own extension

Copy these files into your extension repo:

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
DEMO_USER=alice DEMO_PASS=secret npm run test:e2e
```

## When to use Playwright vs. PHPUnit

- **Playwright** for UI flows: forms, modals, Angular/React widgets, JS
  behaviour, anything you'd otherwise test by clicking around.
- **PHPUnit (headless)** for API/business logic: APIv4 calls, hooks, BAOs,
  workflows. Faster, no browser, no compose stack required for unit tests.
  See the main [Extension development](../../docs/extension-development.md)
  guide for the headless setup.
