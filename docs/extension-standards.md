# Extension standards: what a modern extension looks like

The checklist the civikitchen tooling (cklint / CiviKitchen phpcs standard,
ckmodernize, phpstan, the extension template) enforces or expects. Use it for
audits and as the target state when modernizing an existing extension.

For a new civix extension, run
`/path/to/civikitchen/tools/ckinit.php <extension-directory>` to apply the
versioned `template/extension/` tooling layer. Existing files remain untouched
unless `--force` is explicitly supplied. For an existing extension,
`ckinit.php --check` reports where template-managed files have drifted and
`ckinit.php --update` refreshes them (seeded files like `composer.json` and
`phpstan.neon.dist` stay the repo's own after the first copy) — see
[extension-development.md](extension-development.md#civix-workflow). Use the
`ckinit.php` from the civikitchen checkout at the version the repo pins;
[releases.md](releases.md) explains what a version covers.

## UI: declarative before imperative

- **Listings/reports** → SearchKit `SavedSearch` + `SearchDisplay` (managed),
  not `CRM_Core_Page`/`CRM_Report_Form` + Smarty. Custom reports are
  deprecated in core.
- **Forms** → Afform/FormBuilder (`ang/*.aff.html`), custom handling via
  `hook_civicrm_afformSubmit` or APIv4 actions — not QuickForm
  (`CRM_Core_Form`).
- **Data endpoints** → APIv4 actions, not page callbacks.
- Legitimate exceptions (raw callback endpoints, iframe hosts, third-party
  framework bases like CiviRules' `CRM_CivirulesActions_Form_Form` that
  mandate QuickForm) — suppress with
  `// phpcs:ignore CiviKitchen.Legacy.NoLegacyPageForm` and say why.
- Enforced (as a warning) by `CiviKitchen.Legacy.NoLegacyPageForm`; the sniff
  only sees the direct `extends`, so audits should still grep for `.tpl`
  templates and page routes.
- Afform: `permission` in `*.aff.json` is declared `data_type => Array` in core
  (`Civi\Api4\Afform`), and all 36 afforms core ships use the list form
  `["access foo"]`. Prefer it. A plain string is tolerated but non-canonical —
  core silently `explode(',')`s it, so a permission name containing a comma
  would be split into two.

## Code

- APIv4 only (`civicrm_api4()` / OO builders) — no `civicrm_api3()`
  (`CiviKitchen.Legacy.NoLegacyCall`). The sniff reads PHP only, so `ckconform`
  additionally rejects `CRM.api3` in JS/Smarty; annotate a genuine exception
  with `ck-allow-api3 -- <reason>`.
- **An APIv4 entity has to exist in the core you claim to support.** Entities
  resolve at runtime, so `\Civi\Api4\Foo` compiles, passes phpstan and passes
  every test that never loads that page — then fatals in production. Check the
  entity's `@since` against `<compatibility><ver>`, and remember core ships
  entities from bundled extensions (`ext/civi_mail` …), which then belong in
  `<requires>`. `ckconform` verifies each referenced entity exists in the core
  it runs against.
- `E::ts()`, never bare `ts()` (`CiviKitchen.I18n.UseExtensionTs`).
- Standard mixins for managed entities / menu / settings / Angular — no
  bespoke hooks (`CiviKitchen.Extension.UseMixinsForStandardHooks`).
- Config as managed entities (`managed/*.mgd.php` or `.mgd.php`), not
  install-time imperative code.
- phpstan level 10 clean (template `phpstan.neon.dist`), files ≤ 1000 lines
  (`CiviKitchen.Files.MaxFileLength`).

## Tooling every repo must have

- `phpcs.xml.dist` referencing `<rule ref="CiviKitchen"/>` (project layer on
  top is yours) — `cklint` picks it up automatically.
- `phpunit.xml.dist` + headless tests per the template
  (`template/extension/`), incl. the `TEST_DB_DSN` bootstrap guard.
- `phpstan.neon.dist` (level 10, no baseline).
- CI per `template/extension/.github/workflows/ci.yml` — a thin caller of the
  reusable `extension-ci.yml` in civikitchen (compose stack → cklint +
  ckconform → phpunit under ckcoverage → phpstan → template-drift check), so
  the pipeline is defined once instead of copy-pasted per repo. The caller pins
  the released major (`@v1`) and the CI stack the matching `:v1` image —
  workflow, template, tools and images are one versioned contract, so they move
  together and deliberately ([releases.md](releases.md)). One canary repo
  tracks `@main` and declares that in its `.ckconform`.
- Releases through the shared `extension-release.yml` — a tag push builds the
  installable zip (dev/CI files excluded), installs it into a fresh CiviCRM and
  publishes the GitHub release. The version lives in `info.xml` and
  `composer.json` and they are bumped together; `ckrelease check` is what says
  so out loud. See [Releasing an extension](extension-releases.md). Not a
  template-managed file yet, so adoption is per repo and one line.
- `composer.json` with the extension metadata; no `node_modules`/`vendor`/build
  artifacts committed (frontend builds commit only `dist/`).
- `.gitignore` covers every artifact the repo can regenerate — the phpunit
  result cache, `vendor/`, `node_modules/`, `*.tsbuildinfo`. `ckconform` demands
  only what the repo can actually produce, and only those: nagging a PHP-only
  extension about `node_modules` is how a checker teaches people to stop reading
  it. Prevention, not detection — phpunit writes its cache next to the config on
  every run, so a `git add -A` right after a test run commits it.
- **Lockfiles are committed** — every tracked `package.json` needs its
  `package-lock.json`/`bun.lock`/…, and a `composer.json` with real
  dependencies needs its `composer.lock`. Never `.gitignore` one: without it
  nobody can reproduce the build that shipped, and a red CI run cannot be told
  from a moved dependency. CI installs with the frozen form (`npm ci`). This is
  the exact counterpart of the rule above: ignore what a build regenerates,
  commit what pins it.
- `info.xml` `<requires>` naming every extension actually used (SearchKit,
  Afform, CiviRules …) — a missing `<ext>` only surfaces on a fresh site.
- Dev stack: `.docker/docker-compose.yml` on a civikitchen image. **Every image
  in it is pinned** — a bare `image: mariadb` is `:latest` spelled shorter.
  Floating tags in a workflow make a run unattributable; floating tags in the
  stack CI boots stop the run happening at all. On 2026-07-06 `maildev:latest`
  moved to a release candidate whose healthcheck queries a route its own app
  answers with 404, and six stacks stopped coming up with no diff to point at.
  The project's own image is the one exception: it is referenced through
  `${CIVIKITCHEN_IMAGE:-…}` and is meant to track its tag.
- Every workflow declares a `permissions:` block. Without one the job token
  inherits the repository default, which on older repos and orgs is write-all —
  a lint job does not need to be able to push. Set it per job where a step
  genuinely writes (`packages: write` to push an image).

## Tests and coverage

- Every extension with PHP source needs `tests/phpunit`. A config-only
  extension may opt out in `.ckconform` — `tests=optional -- <reason>` — and
  the reason is not optional.
- `phpunit.xml.dist` must declare a `<coverage>` section scoped to real
  extension code (exclude the civix shim and DAO/BAO boilerplate). Without it
  `--coverage-text` measures nothing while still looking like a passing gate.
- CI runs the suite **with** coverage: `ckcoverage` (or at minimum
  `phpunit --coverage-text`).
- `ckcoverage` reports line coverage and fails below the `min_coverage` floor
  in `.ckconform`. Adopt it in that order: **measure first, set the floor to
  what you actually have, then ratchet it up.** A floor nobody measured only
  teaches people to ignore a red build — and a floor must never be lowered to
  turn one green.

Licence declarations (`info.xml`, `composer.json`, every `package.json`) must
agree with each other. *Which* licence is your policy, not this standard's, so
pin the expected values in an optional `.ckconform` in the extension root and
`ckconform` will enforce them — that file lives in your repo, so a private
policy never has to be published here:

```
license=Proprietary          # info.xml <license> + composer.json
npm_license=UNLICENSED       # every tracked package.json
copyright=Example Ltd        # must appear in LICENSE.txt
template_custom=phpcs.xml.dist -- <reason>   # deliberate template deviation (ckinit --check/--update)
```

SPDX disjunctive licensing (`"license": ["MIT", "GPL-2.0"]`) is allowed in both
manifests, and satisfies the policy when the expected licence is one of the
members — permitted, but not unchecked: an unread array would be a hole straight
through every licence rule.

The `<url desc="Licensing">` civix scaffolds points at the AGPL text. Relicensing
usually edits `<license>` and leaves the link — and a reader trusts the link over
the tag, so `ckconform` fails when the two disagree. A closed-source package also
wants `"private": true` in `package.json`: `UNLICENSED` states intent, `private`
is what makes `npm publish` refuse.

The tooling section is machine-checked by `ckconform` (run from the extension
root) — CI should run it alongside cklint.

## Frontend: JS dependencies, JS tests and browser tests

An extension with a `package.json` has three things the default CI run does
not do: install the tree (nothing is committed — see the lockfile rule above),
run the JS unit suite, and drive the UI in a browser. Five more opt-in inputs
on `extension-ci.yml`, all off by default:

| Input | What it adds |
|---|---|
| `npm_ci` | `npm ci --ignore-scripts` on the runner, before the stack boots. The stack bind-mounts the checkout, so `node_modules/` is there for PHP code that reads an asset bundle. |
| `js_tests` | Runs `npm test`. Implies `npm_ci`. Fails when `package.json` has no `test` script. |
| `bun` | Uses Bun for all of the above instead of npm: `bun install --frozen-lockfile`, `bun run test`, `bun run test:e2e`. Implies the install, the way `js_tests` implies `npm_ci`. Needs a committed `bun.lock`. |
| `playwright` | Own job: boots the stack with port 8080 published and an `admin` / `admin` demo user, then runs `npm run test:e2e` from the runner. Report and traces are uploaded on failure. |
| `node_version` | Node for all of the above. Default `'20'`. Still applies under `bun` — see below. |

Two scripts, two suites, and the names are the contract:

- **`test`** — the JS unit suite, in whatever runner the repo picked (vitest,
  bun, `node --test`). The workflow only knows the standard script slot; what
  it starts is your business. For a pure-function suite this is the bulk of
  the frontend's coverage, so it is the one to have first.
- **`test:e2e`** — the Playwright suite. Not `test`, precisely so that
  enabling `js_tests` never accidentally starts a browser run, and so both
  suites stay separately reachable from CI.

`--ignore-scripts` is fixed, not an input. An `install`/`postinstall` hook is
arbitrary code from anywhere in the dependency tree, running against a
writable checkout with a token in the environment, and nothing in an extension
build needs one at that moment. A package that really must build before its
tests should do it from its own `test` script, where it is visible in the log.

`npm_ci` and `js_tests` cost seconds and belong in the push run:

```yaml
    with:
      key: myextension
      js_tests: true      # implies npm_ci
      # bun: true         # same steps, run with Bun instead of npm
```

### Bun instead of npm

`bun: true` switches the package manager for every frontend step at once —
the install, `js_tests`, and the Playwright job. It is deliberately not
per-step: a repo that resolves its dependencies with npm in one job and with
Bun in another is testing two different trees.

- **`bun.lock` must be committed.** The install is
  `bun install --frozen-lockfile`, which refuses without one — the same rule
  the lockfile section already states, and the reason the npm dependency
  cache is switched off on this path (it keys on `package-lock.json`, and
  `setup-node` fails the step when it cannot find one).
- **Node is still installed**, at `node_version`. Bun replaces npm, not the
  runtime: `bun run` starts your scripts, and what those scripts call —
  `tsc`, `vitest`, `playwright` from `node_modules/.bin` — is a
  `#!/usr/bin/env node` shebang. So the two inputs do not compete; there is
  no Bun equivalent of `node_version`, the workflow installs the Bun the
  setup action ships.
- **`--ignore-scripts` has no counterpart.** Bun runs no dependency lifecycle
  script at all unless it is listed as a `trustedDependency`, which is what
  the flag buys on the npm path. It *does* run your own package's install
  scripts, which `npm ci --ignore-scripts` here does not — keep the build in
  the `test` script either way, and the two paths behave the same.
- **`npx playwright install` stays `npx`** in the browser job. That is not a
  package-manager call but the local binary either manager has just placed in
  `node_modules/.bin`.

The browser job does not belong in the push run: it boots a stack, installs
browsers and is the slowest thing in the pipeline. Put it in the scheduled
`compat.yml` caller below, next to the matrix — `playwright: true`.

What that job assumes, because the shared workflow cannot ask:

- the stack is the standalone CI stack from the template
  (`CIVIKITCHEN_SITE_URL=http://localhost:8080`). The port is fixed to 8080 to
  match it: CiviCRM bakes that URL into every asset and redirect, so a site
  published elsewhere serves links to a port that isn't listening. A CMS
  compose file boots fine, but its login is the CMS's, so the suite has to
  handle that itself — the demo user the job creates is a Standalone one.
- the suite reads `CIVICRM_BASE_URL`, `DEMO_USER` and `DEMO_PASS` from the
  environment (the starter's `playwright.config.ts` and `tests/auth.setup.ts`
  already do). The job sets all three; a hardcoded `localhost:8080` works too.
- browsers are installed with `playwright install --with-deps`, all of them,
  so a config with `webkit` or `firefox` projects works without another input.

Copy-pasteable starter, including the config files and the `test:e2e` script:
[`examples/extension-with-playwright/`](../examples/extension-with-playwright/).

The job runs once, on `image` — it is not multiplied by `matrix_images`.
UI behaviour that breaks per CiviCRM version is real but rare, and a browser
run per image is the most expensive thing this pipeline could do by default.
If you need it, say so on the issue rather than working around it in a
repo-local job.

## Private dependencies

civikitchen is public and the shared workflow needs no token to be called —
but not every extension's *dependencies* are public. Two cases, two opt-in
inputs plus one optional secret each. Both default to off; a caller that sets
neither gets exactly the run it has today.

| Input / secret | What it adds |
|---|---|
| `composer_install` | `composer install --no-dev --no-interaction --no-progress` on the runner, before the stack boots. |
| `composer_deploy_key` (secret) | Private SSH key used for `github.com` during that install, so a private VCS package resolves. |
| `sibling_repo` | `owner/repo` of a second extension: checked out to `.civikitchen-siblings/<repo>` and bind-mounted read-only into the stack, which also enables it. |
| `sibling_deploy_key` (secret) | Private SSH key for that checkout. |

**`composer_install` is not an optimization — for a repo without a committed
`vendor/` it is the precondition for everything else.** The extension's main
`.php` file requires the autoloader at the top, so the entrypoint cannot even
enable the mounted extension: the stack boot fails before a test runs. Commit
`vendor/` or set this input; there is no third option.

It runs on the **runner**, not through the image's own
`CIVIKITCHEN_AUTO_COMPOSER`, and that is the whole point: the deploy key never
enters a container. The container-side auto-composer then sees `vendor/`
through the bind mount and skips the directory, so the two do not collide.
`--no-dev` is deliberate — `phpunit`, `phpstan` and `phpcs` come from the
image and have no business in your `require-dev`.

**`sibling_repo`** is for the extension that implements another extension's
interfaces: the classes must exist at boot (`cv ext:enable` wants the declared
requirement present), and `phpstan` and the test bootstrap resolve them from
the sibling's ext directory. The mount target is the sibling's **extension
key**, read from its `info.xml` — not its repo name, which is free to differ
and is not what CiviCRM registers it under. That is the directory a
`scanDirectories` entry has to point at:

```neon
	scanDirectories:
		- /var/www/html/ext/othersibling/Civi
```

The sibling is mounted **as is**, read-only: no `composer install` runs in it.
A sibling that keeps its own `vendor/` out of git is not supported yet — say
so on the issue rather than working around it.

The checkout lands *inside* your checkout (`actions/checkout` cannot write
outside the workspace) but is not treated as your code: `cklint` ignores
`.civikitchen-siblings/`, and `ckconform` reads tracked files only. Nothing to
add to your `.gitignore` — the directory only ever exists on a runner.

Caller, with both:

```yaml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      key: myextension
      composer_install: true
      sibling_repo: myorg/othersibling
    secrets:
      composer_deploy_key: ${{ secrets.PACKAGE_DEPLOY_KEY }}
      sibling_deploy_key: ${{ secrets.SIBLING_DEPLOY_KEY }}
```

Name the two secrets you pass; do **not** write `secrets: inherit`. Inherit
hands the reusable workflow every secret the repo has, for the sake of two —
and the workflow it hands them to is one you move by retagging. Least
privilege costs one line here.

What goes in those secrets, and why deploy keys rather than a PAT:

- A **read-only deploy key** is scoped to exactly one repository and belongs
  to no person, so it survives someone leaving and cannot reach a second repo
  if it leaks. A PAT is the opposite on both counts. One key per dependency
  repo — that scoping is the entire feature.
- The private half goes into the calling repo's secrets, the public half into
  the dependency repo's *Deploy keys*, with write access **off**.
- The key is configured with `IdentitiesOnly`, so it is the only identity
  offered: a missing grant fails here rather than passing on one runner and
  failing on another because an agent key happened to cover it.

Two limits worth knowing before you switch something else on as well:

- The opt-in extra jobs (`matrix_images`, `upgrade_from_last_release`,
  `playwright`) boot the stack from a plain checkout and are **not** wired up
  for private dependencies. Combining them with these inputs fails fast with
  that message rather than booting an extension that cannot load.
- `composer.lock` still has to be committed — see the lockfile rule above. An
  install that is not reproducible is not a dependency, it is a moving target.

## Compatibility: test the range you claim

`info.xml` `<compatibility><ver>` is a promise; the default CI run checks one
CiviCRM version, on one day, installed from scratch. Three opt-in inputs on the
shared `extension-ci.yml` close that gap. All three default to off — a caller
that sets none of them gets exactly the run it has today.

| Input | What it adds |
|---|---|
| `matrix_images` | One extra job per CiviKitchen image tag (comma-separated): boots the stack and runs the suite against that CiviCRM. |
| `lifecycle` | After the suite, in the running stack: `disable` → `enable` → `uninstall` → `install`, then asserts the extension is installed again. |
| `upgrade_from_last_release` | Installs the newest reachable git tag, swaps the working tree to the tested commit, runs `cv upgrade:db --mode=ext` and asserts no upgrade stayed pending. |

Pick the ends of your claimed range, not everything in between: the oldest
minor you support and current stable. The image tags are `:standalone-<minor>`
(e.g. `:standalone-6.12`) and the moving `:standalone`; see
[images.md](images.md#tags--versions). A `<minor>` tag freezes when upstream
moves on, and it freezes with the `ck*` tooling it was last built with — if you
keep an old minor in a matrix for long, have *Build Dev Images* rebuild it
(`workflow_dispatch` → `extra_standalone_minors`) so it carries current tooling.

The matrix jobs run the **suite**, not the full `ci` pass: `cklint`,
`ckconform`, `phpstan` and the coverage floor are enforced by the tools inside
the image, so re-running them on a pinned older image grades that image's
tooling rather than your extension. What the extra boot answers is the version
question — does it install here, do the tests pass here.

Each entry costs a full stack boot, so **do not put the matrix in the push
run**. Keep `ci.yml` fast (it is what a PR waits on and what automerge gates
on) and add a second, thin caller for the slow checks — one file, one schedule:

```yaml
# .github/workflows/compat.yml
name: Compatibility
on:
  schedule:
    - cron: '0 5 * * 1'   # Mondays, after the weekly image rebuild
  workflow_dispatch:      # and on demand, before a release

permissions:
  contents: read

jobs:
  compat:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      key: myextension
      # This caller gets the default `ci` job too — there is one reusable
      # workflow, not two. Point it at the moving edge instead of repeating
      # the pinned :v1 run every push already does: together with the oldest
      # minor below, the weekly run then covers both ends of the range.
      image: ghcr.io/jfilter/civikitchen:standalone
      # Oldest minor from info.xml <compatibility>.
      matrix_images: ghcr.io/jfilter/civikitchen:standalone-6.12
      lifecycle: true
      upgrade_from_last_release: true
      # Browser tests, for a repo that has a test:e2e script — another stack
      # boot, which is exactly why it lives here and not in ci.yml.
      # playwright: true
      # The drift job already runs on every push in ci.yml.
      check_template: false
```

A scheduled workflow only runs from the default branch, so a red weekly run
means the released state is broken — worth an issue, not a rerun.
`workflow_dispatch` is there for the moment that matters most: before cutting a
release.

`compat.yml` is **not** template-managed: which versions you claim is your
policy, so `ckinit` neither stamps nor checks this file.

## Workflow

`cklint` → `phpstan` → `CIVICRM_UF=UnitTests phpunit` locally and in CI;
`ckmodernize` for mechanical migrations. See
[extension-development.md](extension-development.md).
