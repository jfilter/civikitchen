# Reusable CI building blocks

`extension-ci.yml` remains the complete default for a CiviCRM extension. Three
smaller reusable workflows cover checks that several extensions need beside
that stack without copying checkout, toolchain, artifact, cleanup, and runner
selection boilerplate.

An extension with an additional PHPUnit configuration should keep using the
main workflow rather than create a toolbox job:

```yaml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      key: example
      extra_phpunit_config: phpunit-unit.xml.dist
```

That suite runs after the standard headless coverage suite and reuses the same
materialized Composer dependencies, optional sibling checkout, and container.

All jobs resolve their runner in the same order:

1. the workflow's `runs_on` input;
2. the caller repository's `CK_RUNS_ON` variable;
3. `ubuntu-latest`.

## Frontend CI

`frontend-ci.yml` installs an npm project and runs the non-empty typecheck,
lint, test, build, and output-verification commands in that order. Commands are
passed through environment variables rather than interpolated into shell source.

```yaml
jobs:
  frontend:
    uses: jfilter/civikitchen/.github/workflows/frontend-ci.yml@v1
    with:
      working_directory: frontend
      cache_dependency_path: frontend/package-lock.json
      typecheck_command: npx tsc --noEmit
      lint_command: npm run lint
      test_command: npm test
      build_command: npm run build
```

## Standalone Playwright E2E

`playwright-e2e.yml` is for repositories whose browser suite owns its stack.
It provides ordered install, browser-install, prepare, test, diagnostics,
artifact, and always-run cleanup phases. Commands run inside
`working_directory`; use an explicit `cd` when a diagnostic needs repository
root paths.

```yaml
jobs:
  e2e:
    uses: jfilter/civikitchen/.github/workflows/playwright-e2e.yml@v1
    with:
      install_command: npm ci
      browser_install_command: npx playwright install --with-deps chromium
      prepare_command: bash setup.sh
      test_command: npx playwright test
      cleanup_command: bash teardown.sh
```

The `playwright` input on `extension-ci.yml` is still preferable when the suite
uses the standard CiviKitchen compose stack. This standalone workflow exists
for suites with their own setup and teardown contract.

## Repository command checks

`command-check.yml` checks out the caller and runs one cheap repository-owned
command. It is intended for guards such as public-safety or generated-file
checks that need no CiviCRM stack.

```yaml
jobs:
  guard:
    uses: jfilter/civikitchen/.github/workflows/command-check.yml@v1
    with:
      command: bash tools/qa/check-public-safe.sh
```

Deployment credentials and product-specific orchestration do not belong in
these workflows. Keep deploys, image fleets, tenant discovery, and bespoke
service topologies in the repository that owns them.
