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
