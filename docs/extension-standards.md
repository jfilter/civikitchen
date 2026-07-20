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
- `composer.json` with the extension metadata; no `node_modules`/build
  artifacts committed (frontend builds commit only `dist/`).
- Dev stack: `.docker/docker-compose.yml` on a civikitchen image.

The tooling section is machine-checked by `ckconform` (run from the extension
root) — CI should run it alongside cklint.

## Workflow

`cklint` → `phpstan` → `CIVICRM_UF=UnitTests phpunit` locally and in CI;
`ckmodernize` for mechanical migrations. See
[extension-development.md](extension-development.md).
