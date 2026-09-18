# Changelog

All notable changes to civikitchen are documented here. A release is one
versioned contract — the reusable workflows, the extension template,
`scaffold/ckinit.php` and the images ship together, and a consumer pins `@v1`
and `:v1` (see [Releases](docs/releases.md)). Entries are written for that
consumer: what changes for a repo calling the workflows, running the toolbelt,
or pulling an image.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
except that a break the consumers are adjusted for ships as a minor, marked
**Breaking** ([versioning rules](docs/releases.md#versioning-rules)).

## [Unreleased]

## [1.25.0] - 2026-09-18

### Added

- `ckconform`'s `settings-metadata` check rejects a `table` pseudoconstant whose
  keys core's settings code does not read. `key_column`/`label_column` and the
  other snake_case spellings leave `keyColumn`/`labelColumn` `NULL`, and the
  first page that loads options for settings fatals with "not of the type
  MysqlColumnNameOrAlias" — `/civicrm/admin/theme` loads them for *every*
  setting, so one malformed extension breaks an unrelated core screen. A
  `table` pseudoconstant without both `keyColumn` and `labelColumn` fails for
  the same reason. Findings are failures, not warnings.
- The lifecycle gate runs the same check against the installed extension: after
  re-enable, `cklifecycle` calls `Civi\Core\SettingsMetadata::getMetadata()`
  with options loading on for every setting in `settings/*.setting.php`. Neither
  install nor the test suite loads options, so this was previously invisible
  until someone opened a settings page. Repos without settings are unaffected.

### Fixed

- The managed headless bootstrap keeps the core foreign keys across runs. A
  second `ckphpunit` against the same warm `civicrm_test` used to leave the
  schema with almost none of them (285 -> 3 constraints), because
  `CiviEnvBuilder::apply()` signs `CoreSchemaStep` before comparing signatures
  and that generator drops every constraint it emits; on the warm path
  `apply()` returns before re-adding them. `ck_headless()` now replays the
  cached core schema SQL once per process. Tests expecting `ON DELETE CASCADE`
  stop failing on every run but the first.
- `ck_headless()` discards the session status messages the environment build
  itself queues — ten "Unknown entity SearchDisplay" errors from the managed
  entity reconciliation during `Data::populate()`. A test asserting status
  messages was red on a cold database and green on a warm one.
- Both land in `tests/phpunit/ckHeadless.php`, so consuming repos need
  `ckinit --update`.

## [1.24.2] - 2026-09-14

### Fixed

- A retried demo image build re-downloads the CMS archive instead of reusing the
  error page the failed attempt cached. buildkit's `extract-url` stores whatever
  the server returned under the URL's hash, so a transient 5xx used to make all
  three `civibuild create` attempts fail identically; unusable archives are now
  dropped between attempts and the failure names the host that was downloading.

## [1.24.1] - 2026-09-14

### Fixed

- The seeded `phpstanBootstrap.php` passes `cklint` and `ckfmt` again, so a repo
  on `ckinit --update` no longer fails its own lint gate; the dev-image test now
  lints and formats the stamped template and keeps it that way.

## [1.24.0] - 2026-09-14

### Added

- `extension-release.yml` ships build output a repo does not commit. The repo
  declares it in `civikitchen.yaml` under `policy.dist.build` (`tool: bun` and
  its `outputs`); the release runs `bun install --frozen-lockfile` and
  `bun run build` on the Bun `package.json` pins, and `ckrelease dist` stages
  exactly those untracked paths into the archived tree, on top of
  `composer_install` too. A missing or tracked output, an output the archive
  leaves out, or a missing `packageManager` pin or `bun.lock` fails the release,
  and `ckrelease verify` requires every declared output in the zip.

### Fixed

- `ckrelease` prints why `ckconform --dist-paths` refused the release layout
  instead of only reporting that it could not read it.

## [1.23.0] - 2026-09-14

### Added

- **Breaking** `release-tags` conformance check: a version bumped past without
  its git tag fails, and the failure names every untagged version. Versions that
  deliberately never got a tag are declared under `policy.untagged_versions` in
  `civikitchen.yaml` (bare `info.xml` version, quoted — an unquoted `1.0` is a
  YAML float).
- **Breaking** `managed-job` conformance check: a scheduled job whose parameters
  `CRM_Core_BAO_Job::parseParameters` rejects fails, as does an APIv4-only
  entity declared without `version=4`. The check recognises the civix
  `api/v3/<Entity>/<Action>.php` layout.
- `sibling_repo` entries may pin a tag, branch or commit (`repo@ref`) instead
  of tracking the default branch.
- MariaDB 12.2 in the database compatibility matrix.
- `extension-release.yml` publishes a `vX.Y.Z-<pre-release>` tag as a GitHub
  pre-release that never takes Latest. Callers extend their tag trigger with
  `'v[0-9]+.[0-9]+.[0-9]+-*'` to release them; a tag the trigger lets through
  that is no SemVer version fails the run before the build.

### Changed

- **Breaking** for callers with hard-coded sibling paths: private
  dependencies are checked out under the extension key, one directory per key.
  A `prepare_command` that spells `.civikitchen-siblings/<repo name>` has to
  read `CK_SIBLING_DIR` or use the key instead.
- Every stack-booting job in `extension-ci.yml` wires `sibling_repo` and
  `composer_install`, so a job that boots a stack can install an extension
  whose `<requires>` names a private sibling. `playwright-e2e` checks siblings
  out through the same private-dependency action.
- `phpstanBootstrap.php` autoloads core's own bundled `ext` packages, so
  analysis resolves classes from core extensions.
- A failed private-dependency fetch says which pinned commit could not be
  fetched and why.

### Fixed

- `api3-surface` recognises APIv4 scheduled jobs configured through JSON
  parameters.
- `ckdeps` treats the Symfony components core ships as core-provided.
- `ckconform` reads `policy.tests=optional` with its mandatory reason in
  `ckcoverage`.
- Image scan: excuse CVE-2026-84375 in core's bundled js-yaml.
- `extension-release.yml` marks a release Latest only when its tag is the
  highest plain `vX.Y.Z` in the repo; a late release of an older version no
  longer takes Latest from the newest one.
- `permission-closure` no longer reads an entity field named `permission` as a
  permission spec, nor array subscripts, string subscripts, call arguments or a
  string that is only part of a concatenation inside a spec, and reads every
  permission of a nested OR list instead of stopping after the first group or
  at an arrow function. Escaped quotes and `\u{…}` escapes in a permission
  string are resolved in specs, `CRM_Core_Permission::check()` calls and
  `hook_civicrm_permission`, and a numeric permission string no longer aborts
  the check.
- `permission-closure` accepts core's `*always deny*` sentinel and afform's
  `@afformPageToken` and `manage own afform`.
- `permission-closure` finds `CRM_Core_Permission::check()` calls in any letter
  case and reads their list and named `permissions:` arguments, and a brace
  inside a string or comment no longer moves the end of
  `hook_civicrm_permission`. A by-reference `hook_civicrm_permission` and every
  `getPermissions()` in a file contribute definitions.

## [1.22.0] - 2026-09-12

### Added

- `sibling_repo` accepts an ordered list of private dependencies, checked out
  in the order given.

### Fixed

- `ckdeps` treats the Symfony components bundled with CiviCRM core as
  core-provided instead of demanding a composer requirement.

## [1.21.6] - 2026-09-07

### Fixed

- The osv-scanner download is retried, and a lint failure points at the local
  fixer commands.

## [1.21.5] - 2026-09-07

### Added

- The template's CI workflow can be dispatched manually.

### Fixed

- Buildkit first boot: the healthcheck reports healthy only once Apache answers
  with a non-error, and provisioning refuses a site whose extension directory
  `cv` cannot name instead of half-installing.
- A sentinel probe distinguishes an unanswerable check from an absent site, so
  a reinstall is not triggered by a slow container.
- `ckmodernize` resolves the core directory through `cv` and stops when there
  is none.
- The dev-tools test prints what `ck` produced when a check fails; the catalog
  preview cron opens an issue when it fails.

## [1.21.4] - 2026-09-07

### Changed

- Catalogs regenerated from CiviCRM 6.18.0.

### Fixed

- Tool and trivy downloads fail and retry instead of hashing an error page.

## [1.21.2] - 2026-09-07

### Fixed

- `extension-release` tears the smoke stack down whatever the outcome.

## [1.21.1] - 2026-09-07

### Fixed

- The private-dependency token action is passed the GitHub App id as
  `client-id`, the input it now expects.
- The `<requires>` resolver survives a caller's `set -e` when a dependency is
  absent.

## [1.21.0] - 2026-09-07

### Changed

- `extension-release` resolves a private dependency from the staged release
  instead of from inside the container.

## [1.20.1] - 2026-09-04

### Fixed

- `extension-release` runs the toolbelt entrypoints with `php`, not `bash`.

## [1.20.0] - 2026-09-03

### Added

- The standalone Playwright E2E workflow supports sibling extensions.

## [1.19.2] - 2026-09-03

### Fixed

- `ckcoverage` no longer reads `policy.tests=optional` as required.

## [1.19.1] - 2026-09-02

### Added

- A coverage gate for the shared PHP code.

### Changed

- Image builds apply Debian security updates and the daily rebuild runs without
  cache.
- Extension monorepo layouts are supported by the shared workflows.

### Fixed

- `info.xml` `<requires>` is exempt from the foreign-internals PHPStan rule.

## [1.19.0] - 2026-08-31

### Added

- Versioned extension sources and a shared PHP CLI behind the `ck*` tools;
  structured script logic moved from shell into that shared PHP.

## [1.18.1] - 2026-08-31

### Fixed

- Release retagging preserves the verified image digest instead of rewrapping
  the manifest.

## [1.18.0] - 2026-08-31

### Changed

- One validated `civikitchen.yaml` covers both scenario and repository policy
  configuration.
- Contact Layout is limited to the bundled CMS images, kept under core
  ownership, and skipped on Joomla where it is unavailable.

### Fixed

- Demo smoke tests hand long Basic Auth credentials to curl instead of wrapping
  the header.

## [1.17.0] - 2026-08-30

### Added

- Playwright runs on hardened runners.
- Repository secret scanning.

### Fixed

- `ckinit` tests are portable across sed variants.

## [1.16.10] - 2026-08-27

### Fixed

- The `<requires>` resolver asks `Extension.get` locally and aborts on a failed
  query instead of downloading.

## [1.16.9] - 2026-08-27

### Changed

- `ckinit` stamps the CI caller and the CI compose file as managed blocks, so a
  repo's own additions outside the markers survive an update.

## [1.16.8] - 2026-08-27

### Fixed

- Bun jobs install the version the repo pins in `package.json`.

## [1.16.7] - 2026-08-27

### Changed

- Artifact actions moved to their Node 24 releases.

## [1.16.6] - 2026-08-27

### Fixed

- `phpstanBootstrap.php` aliases the civix `CRM_<Prefix>_DAO_Base` to
  `CRM_Core_DAO_Base`.
- `ckdeps` ignores the template files' `simplexml` use; `ck_heal_perms` stays
  off bind mounts.

## [1.16.5] - 2026-08-27

### Fixed

- Releases strip nested `.git` directories from `vendor/` and authenticate
  composer dist downloads; `ckdeps` treats the phpstan bootstrap as dev code.
- The auto-composer step hands `vendor/` and `composer.lock` back to the mount
  owner even after a failed install.

## [1.16.4] - 2026-08-27

### Fixed

- Concurrency groups are keyed per job by literal name, and each
  `playwright-e2e` call gets its own compose project.

## [1.16.3] - 2026-08-27

### Changed

- A superseding push cancels the previous `extension-ci` run on the same ref.

## [1.16.2] - 2026-08-27

### Fixed

- `ck_headless()` installs one step per `info.xml` requires and makes no
  extension-system call before the headless rebuild.

## [1.16.1] - 2026-08-27

### Fixed

- `playwright-e2e` hands root-owned test artefacts back to the runner user.
- The template's `ckHeadless.php` and `phpstanBootstrap.php` pass `cklint` and
  `ckfmt`.

## [1.16.0] - 2026-08-27

### Added

- An organisation-wide `.ckconform` defaults layer under the repo policy, via
  the `policy_defaults` input or the `CK_POLICY_DEFAULTS` variable.
- A managed `renovate.json`, its preset stamped from the `renovate_preset`
  policy key.
- `ckup` picks host ports for the template compose stack and leaves ports
  already in `.docker/.env` alone.

### Changed

- **`extension-ci.yml` reads the extension key from `info.xml`** instead of a
  caller input.
- Bind-mounted extensions are enabled automatically; extra ones are pinned via
  `.ckconform` `extension_source`, and core locales are installed on request.
  The release smoke test resolves `info.xml` requires instead of taking an
  `extra_extensions` input.
- `ck_headless()` takes its `<requires>` from `info.xml`, and
  `phpstanBootstrap.php` autoloads every mounted one.
- `ckconform` trusts the hooks a present `<requires>` extension dispatches
  instead of requiring them in `known_hooks`.

## [1.15.0] - 2026-08-26

### Added

- `profile-schema` 0.4.0: `contentProfile` takes `authx` and `apiUsers`,
  `config` takes `membership_types`.
- `info.xml` requires are resolved at first boot, pinned via `.ckconform`
  `extension_source`.

### Changed

- Private-dependency app tokens are scoped to `contents:read`, with an
  exemption for the owner-wide installation scope.
- CiviBanking pinned to 1.4.1 in the `verein` profile.

### Fixed

- Core test fixtures are restored in the standalone images.
- `ckcoverage` log collisions.

## [1.14.1] - 2026-08-19

### Fixed

- Image scan: the Go stdlib CVEs in the prebuilt tsgolint binary are covered
  and excused until oxlint rebuilds.

## [1.14.0] - 2026-08-19

### Changed

- **Private dependencies are fetched with a GitHub App token over HTTPS**
  instead of deploy keys; the app secrets live in the calling repos. GitHub
  host keys are pinned rather than scanned, which unblocks proxied runners.
- Composer-managed dev tools install from committed lockfiles, and a test
  checks those lockfiles for sync and against the PHP floor.

### Fixed

- psalm resolves on PHP 8.1.31 so `cktaint` runs on older images.

## [1.13.1] - 2026-08-15

### Fixed

- Large extension release archives are handled.

## [1.13.0] - 2026-08-14

### Added

- Composer-backed extension releases.
- Atomic civix extension scaffolding.
- Extension CI installs development dependencies.

## [1.12.0] - 2026-08-14

### Added

- Modular reusable CI workflows beside `extension-ci.yml`, callable
  individually.

## [1.11.2] - 2026-08-14

### Added

- A dedicated annotation for optional dependency guards.

## [1.11.1] - 2026-08-14

### Fixed

- Explicitly guarded optional CiviRules adapters are allowed.

## [1.11.0] - 2026-08-14

### Added

- `ckcivix` reports drift between the civix scaffold and the current format.
- Release integrity checks in `ckconform`.

### Changed

- Test analysis is a reliable default in PHPStan.
- The formatter owns scope indentation; the phpcs standard yields.

## [1.10.3] - 2026-08-14

### Fixed

- `ckfmt` owns array continuation indent.
- Image builds are serialised so the newest commit promotes last.

## [1.10.2] - 2026-08-14

### Fixed

- `ckfmt` owns nested-array layout in the phpcs standard.
- phpcs receives one `--ignore`, so `vendored_paths` survives.
- Node scripts and e2e specs get the node env in `ckeslint`.

## [1.10.1] - 2026-08-13

### Added

- `make doctor` reports every missing host prerequisite in one pass.

### Changed

- A repo whose entity schema ships no DAO stub fails `ckconform`.
- The per-run compose stack is torn down at job end.

## [1.10.0] - 2026-08-11

### Added

- A `civikitchen.yaml` repo may declare vendored source and non-Smarty message
  templates.
- Supported standalone minors are pinned in `versions.env`; a weekly catalog
  preview runs against the latest core release.

### Changed

- **The standalone image installs CiviCRM from the release tarball** instead of
  building `FROM civicrm/civicrm`.
- Repository layout: `images/` split into `docker/`, `toolbelt/` and `tests/`;
  `tools/` and `template/` merged into `scaffold/`. Container paths are
  unchanged.
- Every stack-booting CI job gets its own compose project, so one job's
  `down -v` cannot kill another's containers.
- Catalogs regenerated from CiviCRM 6.17.2; the phpcs floor raised to the
  release fixing CVE-2026-67434.
- `ckconform` gates on tracked files, resolves gitignore through
  `git check-ignore`, and treats fixture entities as not shipped.
- `cktaint` is documented as blocking, matching the bundled config.

### Fixed

- `ck_json_field` no longer warns on a missing file.
- A `.ckconform` line that declares nothing is reported.

## [1.9.0] - 2026-08-05

### Changed

- `cktaint` blocks on SQL, shell, include, unserialize and SSRF taint; the rest
  stays advisory. The `.docker` dev harness is outside the scan scope.

## [1.8.0] - 2026-08-04

### Added

- `ckmutate`: mutation testing (infection) as an opt-in scheduled job.

### Changed

- `cktaint` models PSR-7 request input, the SQL builder, Guzzle and the header
  sinks.
- Image-scan exceptions moved to `trivyignore.yaml`.

### Fixed

- `ckphpunit` generates its canary config in a temp dir with absolutized paths.

## [1.7.0] - 2026-08-04

### Added

- PHPStan gains an APIv4 action-parameter report, implicit-join validation,
  raw-SQL table checks against the core schema catalog, and a data-change
  report for GET-reachable route handlers. Test analysis is opt-in via
  `phpstan-tests.neon.dist`.
- A transaction canary for phpunit and `cklifecycle`.
- The buildkit images ship `ckphpunit` and `cklifecycle`.

### Changed

- `ckeslint`/oxlint baseline widened to the suspicious, perf, promise and
  security rule sets, and skips type-aware rules when the repo has no tsconfig.

## [1.6.0] - 2026-08-04

### Added

- `ckcoretest` runs CiviCRM core's phpunit suites in the standalone image.

### Changed

- **`ckeslint` runs oxlint** instead of ESLint.

## [1.5.2] - 2026-08-04

### Fixed

- PHPStan owns the civix namespace even without a CRM tree.
- Reusable-workflow callers are exempt from the Playwright upload rule.
- Stale stacks are torn down before booting on self-hosted runners.

## [1.5.1] - 2026-08-03

### Fixed

- The test bootstrap is exempt from `DeclareStrictTypes`.
- osv scans pass on lockfiles that lock no packages; the trivy job summary is
  truncated rather than lost.

## [1.5.0] - 2026-08-03

### Added

- **`ckfmt`**: mago formats PHP to the phpcs standard, oxfmt formats JS/TS — a
  hard gate in the shared CI.
- **`cktaint`**: psalm as an advisory taint engine for CiviCRM extensions.
- **`ckdeps`** and PHPStan rule packs shipped from the image: an APIv4 contract
  catalog with entity/action/field rules, phpat architecture boundaries,
  deprecation rules, and analysis on the extension's own PHP floor.
- **`ckcompat`**: parse the code at the PHP floor composer declares.
- Runtime gates: Smarty compile smoke, schema parity, ESLint; runtime
  deprecations fail extension test suites.
- Lockfile scanning with osv-scanner and published-image scanning with trivy;
  workflows SHA-pinned and audited by zizmor, with Renovate for updates.
- New conformance and coder checks: APIv4 permission bypasses, scan-classes
  mixin, Smarty 5 removed tags, modernisation checks (mgd v4, mixin versions,
  schema format, api3 surface, raw SQL), inline ignores that suppressed
  nothing, and eslint-style suppressions with mandatory reasons.

### Changed

- `cklint` reports phpcs warnings without failing the gate; mago lint is the
  second engine with a curated baseline pinned to the 8.1 fleet floor.
- Extension standards require `strict_types` per file and flag unnamed boolean
  arguments; rector names arguments instead of padding with defaults.
- Images run Node 24 LTS with a pinned npm.

## [1.4.0] - 2026-08-03

### Added

- `extension-ci` resolves the runner label from the caller's `CK_RUNS_ON`
  variable.

### Changed

- `{ts}` in `.mgd.php` message templates counts as a source string.

## [1.3.0] - 2026-07-31

### Added

- `runs_on` input: run the whole `extension-ci` pipeline on your own runners.
- Opt-in core-upgrade persistence job (`core_upgrade_from`).

## [1.2.2] - 2026-07-31

### Fixed

- `git check-ignore` gets the same `safe.directory` as `Context::git()`.

## [1.2.1] - 2026-07-31

### Fixed

- `ckconform` works under CI's uid mismatch and never judges a sibling
  checkout.

## [1.2.0] - 2026-07-31

### Added

- Opt-in composer install and private sibling-extension checkout in
  `extension-ci`, with a `bun` input for the frontend steps.

### Changed

- `cklint` never lints a CI-checked-out sibling extension.
- `ckcoverage` honours `tests=optional` when there is no phpunit config.

## [1.1.0] - 2026-07-31

### Added

- **`ckrelease`** and the shared `extension-release.yml`: version check, dist
  archive and a tag-triggered release workflow for extension repos.
- Opt-in `extension-ci` jobs: npm materialization, JS unit tests, Playwright,
  a compatibility matrix, lifecycle smoke, and an upgrade test from the last
  release tag.
- i18n conformance: `{ts}` without a covering `{crmScope}` fails, and a shipped
  `l10n/` is checked for coherence.

### Changed

- The three `extension-ci` gates report independently.
- `ckinit` `template_custom` may cover seeded files, absence included.

## [1.0.0] - 2026-07-31

### Added

- The versioned contract: reusable `extension-ci.yml`, the extension template
  with its managed/seeded split, `ckinit --check`/`--update`, the fleet drift
  gate, and the release tags `v1.x.y` / the moving `v1` and `:v1`.
- `ckconform`, rewritten in PHP with tests: repo-structure, licensing, APIv4,
  mixin, Smarty, compose and lockfile conformance checks.
- `ckcoverage`: coverage with an enforced floor.

### Changed

- The template pins the caller to `@v1` and the compose stack to `:v1`.
- First boot no longer wipes a persistent dev database, and the install is
  retryable.

[Unreleased]: https://github.com/jfilter/civikitchen/compare/v1.25.0...HEAD
[1.25.0]: https://github.com/jfilter/civikitchen/compare/v1.24.2...v1.25.0
[1.24.2]: https://github.com/jfilter/civikitchen/compare/v1.24.1...v1.24.2
[1.24.1]: https://github.com/jfilter/civikitchen/compare/v1.24.0...v1.24.1
[1.24.0]: https://github.com/jfilter/civikitchen/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/jfilter/civikitchen/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/jfilter/civikitchen/compare/v1.21.6...v1.22.0
[1.21.6]: https://github.com/jfilter/civikitchen/compare/v1.21.5...v1.21.6
[1.21.5]: https://github.com/jfilter/civikitchen/compare/v1.21.4...v1.21.5
[1.21.4]: https://github.com/jfilter/civikitchen/compare/v1.21.2...v1.21.4
[1.21.2]: https://github.com/jfilter/civikitchen/compare/v1.21.1...v1.21.2
[1.21.1]: https://github.com/jfilter/civikitchen/compare/v1.21.0...v1.21.1
[1.21.0]: https://github.com/jfilter/civikitchen/compare/v1.20.1...v1.21.0
[1.20.1]: https://github.com/jfilter/civikitchen/compare/v1.20.0...v1.20.1
[1.20.0]: https://github.com/jfilter/civikitchen/compare/v1.19.2...v1.20.0
[1.19.2]: https://github.com/jfilter/civikitchen/compare/v1.19.1...v1.19.2
[1.19.1]: https://github.com/jfilter/civikitchen/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/jfilter/civikitchen/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/jfilter/civikitchen/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/jfilter/civikitchen/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/jfilter/civikitchen/compare/v1.16.10...v1.17.0
[1.16.10]: https://github.com/jfilter/civikitchen/compare/v1.16.9...v1.16.10
[1.16.9]: https://github.com/jfilter/civikitchen/compare/v1.16.8...v1.16.9
[1.16.8]: https://github.com/jfilter/civikitchen/compare/v1.16.7...v1.16.8
[1.16.7]: https://github.com/jfilter/civikitchen/compare/v1.16.6...v1.16.7
[1.16.6]: https://github.com/jfilter/civikitchen/compare/v1.16.5...v1.16.6
[1.16.5]: https://github.com/jfilter/civikitchen/compare/v1.16.4...v1.16.5
[1.16.4]: https://github.com/jfilter/civikitchen/compare/v1.16.3...v1.16.4
[1.16.3]: https://github.com/jfilter/civikitchen/compare/v1.16.2...v1.16.3
[1.16.2]: https://github.com/jfilter/civikitchen/compare/v1.16.1...v1.16.2
[1.16.1]: https://github.com/jfilter/civikitchen/compare/v1.16.0...v1.16.1
[1.16.0]: https://github.com/jfilter/civikitchen/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/jfilter/civikitchen/compare/v1.14.1...v1.15.0
[1.14.1]: https://github.com/jfilter/civikitchen/compare/v1.14.0...v1.14.1
[1.14.0]: https://github.com/jfilter/civikitchen/compare/v1.13.1...v1.14.0
[1.13.1]: https://github.com/jfilter/civikitchen/compare/v1.13.0...v1.13.1
[1.13.0]: https://github.com/jfilter/civikitchen/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/jfilter/civikitchen/compare/v1.11.2...v1.12.0
[1.11.2]: https://github.com/jfilter/civikitchen/compare/v1.11.1...v1.11.2
[1.11.1]: https://github.com/jfilter/civikitchen/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/jfilter/civikitchen/compare/v1.10.3...v1.11.0
[1.10.3]: https://github.com/jfilter/civikitchen/compare/v1.10.2...v1.10.3
[1.10.2]: https://github.com/jfilter/civikitchen/compare/v1.10.1...v1.10.2
[1.10.1]: https://github.com/jfilter/civikitchen/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/jfilter/civikitchen/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/jfilter/civikitchen/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/jfilter/civikitchen/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/jfilter/civikitchen/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/jfilter/civikitchen/compare/v1.5.2...v1.6.0
[1.5.2]: https://github.com/jfilter/civikitchen/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/jfilter/civikitchen/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/jfilter/civikitchen/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/jfilter/civikitchen/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/jfilter/civikitchen/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/jfilter/civikitchen/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/jfilter/civikitchen/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jfilter/civikitchen/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/jfilter/civikitchen/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jfilter/civikitchen/releases/tag/v1.0.0
