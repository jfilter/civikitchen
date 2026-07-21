# Extension standards: what a modern extension looks like

The checklist the civikitchen tooling (cklint / CiviKitchen phpcs standard,
ckmodernize, phpstan, the extension template) enforces or expects. Use it for
audits and as the target state when modernizing an existing extension.

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
  (`CiviKitchen.Legacy.NoLegacyCall`).
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
- CI per `template/extension/.github/workflows/ci.yml` (compose stack →
  phpunit → phpstan; add cklint).
- `composer.json` with the extension metadata; no `node_modules`/`vendor`/build
  artifacts committed (frontend builds commit only `dist/`).
- **Lockfiles are committed** — every tracked `package.json` needs its
  `package-lock.json`/`bun.lock`/…, and a `composer.json` with real
  dependencies needs its `composer.lock`. Never `.gitignore` one: without it
  nobody can reproduce the build that shipped, and a red CI run cannot be told
  from a moved dependency. CI installs with the frozen form (`npm ci`).
- `info.xml` `<requires>` naming every extension actually used (SearchKit,
  Afform, CiviRules …) — a missing `<ext>` only surfaces on a fresh site.
- Dev stack: `.docker/docker-compose.yml` on a civikitchen image.

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
```

The tooling section is machine-checked by `ckconform` (run from the extension
root) — CI should run it alongside cklint.

## Workflow

`cklint` → `phpstan` → `CIVICRM_UF=UnitTests phpunit` locally and in CI;
`ckmodernize` for mechanical migrations. See
[extension-development.md](extension-development.md).
