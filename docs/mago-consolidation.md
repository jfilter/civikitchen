# Consolidating the PHP toolbelt on mago

The images ship nine PHP quality tools. This plan moves every job mago can
do onto mago and removes the tool that did it, one gate at a time. A tool
leaves only when mago is shown to find what the tool found, on fixtures and
on real extension code. Where mago has no equivalent, the tool stays.

The decision is [ADR-0017](adr/0017-php-quality-tools-consolidate-on-mago.md).
The mago pin lives in `toolbelt/install-dev-tools.sh` and follows the newest
mago release.

## Target state

| Job | Today | Target |
|---|---|---|
| Syntax check of changed files (`cklint`) | `php -l` | `mago lint` + `mago analyze` |
| Layout (`ckfmt`) | mago formatter | unchanged |
| Style and footgun sniffs (`cklint`) | mago linter + CiviKitchen rules as a mago extension (done) | unchanged |
| Version compatibility (`ckcompat`) | mago lint + mago analyze at the floor (done) | unchanged |
| Static analysis (`phpstan` gate) | phpstan, deprecation rules, disallowed-calls, phpat, CiviKitchen rules | `mago analyze` + CiviKitchen plugins as a mago extension |
| Taint analysis (`cktaint`) | psalm | psalm (mago's `tainted-data-to-sink` is a syntactic rule without data flow) |
| Automated refactoring (`ckmodernize`) | rector | rector (mago has no equivalent) |
| Mutation testing (`ckmutate`) | infection | infection |
| Unused and missing dependencies (`ckdeps`) | composer-dependency-analyser | composer-dependency-analyser |

At the end, the `phpstan-root` composer root and the phpstan extension
package leave the images. phpcs, PHPCompatibility and the civicrm/coder clone
are already gone, and `ckdeps` has its own small composer root
(`toolbelt/ckdeps-root`).

## Evidence so far

Measured with mago 1.55.0 inside the `:standalone` image on 2026-10-11.

**`php -l`.** Of 20 fixtures that `php -l` rejects, `mago lint` rejects 15.
The other five are compile errors that only `mago analyze` reports:
`break` outside a loop, a `continue` level that is too deep, a redeclared
function, assignment to `$this`, and a default value of the wrong type.
`php -l` can leave `cklint` only once `mago analyze` runs on every change,
which is step 3.

**PHPCompatibility.** On 32 version-specific fixtures, checked against an
8.1 floor:

- mago reports four cases PHPCompatibility misses: `str_increment()`,
  `Dom\HTMLDocument`, `ReflectionClass::newLazyGhost()` and the 8.5
  deprecation of `ReflectionProperty::setAccessible()`.
- mago misses five cases PHPCompatibility reports:
  - `"${var}"` interpolation, deprecated in 8.2
  - `get_class()` without an argument, deprecated in 8.3
  - an implicitly nullable parameter, deprecated in 8.4. mago reports it
    only when it runs at 8.4 or later, not at the floor.
  - `mb_trim()`, new in 8.4. mago has no availability data for it.
  - dynamic class constant fetch `C::{$name}`, PHP 8.3 syntax that mago
    parses at an 8.1 floor without a finding
- New functions, classes and methods are reported only by `mago analyze`
  (`unavailable-*`), not by `mago lint`. Deprecated functions and constants
  are reported only by `mago analyze` (`deprecated-*`).

The five gaps are covered by the `civikitchen/php-compatibility` and
`civikitchen/php-deprecation` rules and by running mago's Deprecation rules
at the newest PHP (step 4).
PHPCompatibility also reported functions that CiviCRM core polyfills through
symfony/polyfill-php80 to -php85 (`mb_trim()`, `array_find()`,
`json_validate()`), which an extension may call on any floor; mago reads the
polyfills as symbols and stays silent.

**Static analysis.** On one extension that is clean under phpstan level 10,
`mago analyze` with core as an include ran in 1.2 s, against 4.7 s for
phpstan. It reported 25 issues:

- 20× `mixed-assignment`, a check stricter than phpstan's level 10
- 2× `invalid-array-index` on `$result[] = …` against `Civi\Api4\Generic\Result`.
  These are false positives, because `Result` is an `ArrayObject`.
- 2× `invalid-type-cast` on an array cast of `Result`, not yet triaged
- 1× `incompatible-return-type`: an entity's `create()` returns an action
  that does not extend the parent's `DAOCreateAction`. phpstan misses this
  real defect.

**Extension API.** mago 1.47 added an extension SDK in PHP: custom linter
rules on the syntax tree, analyzer plugins (type providers, targeted hooks,
codebase scans) and issue filters. The CiviKitchen phpcs sniffs and phpstan
rules port to this SDK. The SDK is young, so a port pins its mago version
exactly.

## Port status

`toolbelt/mago-ext` is one mago extension (composer root pinned to the mago
version in `toolbelt/versions.env`). `make test-mago-ext` runs every case in
`toolbelt/mago-ext/tests/cases` through the real mago binary and compares the
exact findings; the image tests run the same cases against the image's mago.

- **Linter rules, gating in `cklint`:** all eight CiviKitchen sniffs and
  Slevomat's `DeclareStrictTypes` (mago's own `strict-types`). The sniffs,
  their PHPUnit test and Slevomat are gone from the phpcs standard. Over the
  38 local extension repos the mago rules and the old sniffs agree on 3017
  findings; the seven differences are six false positives the sniff raised on
  booleans inside nested array literals, and one `phpcs:ignore` that needs to
  become `@mago-expect`.
- **Analyzer plugins, not yet run by any gate:** the APIv4 contract rules
  (calls, fluent fields, action properties and their initialization), the SQL
  table rule, GET mutation, transactional test DDL, silent database catch, the
  `civicrm-disallowed` bans (one list in `civicrm-disallowed.php`, read by both
  tools), the `@ck-legacy` scope as an issue filter, and phpat's architecture
  test as a plugin. Each shares its contract logic with the phpstan rule
  through `toolbelt/phpstan/src`, so the two cannot drift during the shadow
  phase. The architecture plugin reproduces phpat's findings on the same
  fixtures, plus two phpat misses (a property's `@var` type and a static
  property fetch). A local shadow run over 49 extension repos found every
  finding of both tools on the same line once phpstan's suppressions were
  lifted; the differences left are the divergences below.
- **Known gaps, both limits of mago's extension API:** a property
  initialization provider sees the trait but not the class using it, so a
  trait's defaults count as initialized outside an action too; a hook sees one
  class-like's syntax tree, so GET mutation does not trace a handler's call
  into a trait method.
- **Known divergences:** the fluent APIv4 field check types each argument
  where it stands, so mago also checks a field name held in a local variable,
  which phpstan does not; a select list extended in a branch has a union type,
  and mago then skips the alias checks on `orderBy`/`groupBy` that phpstan
  still runs. The bans apply in every repo, while phpstan applies them only
  where `phpstan.neon.dist` includes `civicrm-disallowed.neon`. A class of a
  required extension that is not checked out is a boundary finding in mago
  (its help names the missing extension); phpat skips a class it cannot
  resolve.

## Steps

Each step ends with the old tool removed from `install-dev-tools.sh`, the
lock files and the docs, and with a fixture per removed check that fails
without its replacement.

### 1. Follow the newest mago

- Bump the pin to the newest release and keep bumping it. Each bump derives
  both checksums anew and checks two properties on the extension repos:
  - formatter output is still clean under `cklint`
  - the lint stage produces no new findings
- Report the five compatibility gaps and the `analyze`-only compile errors
  upstream. A gap fixed there retires its part of the
  `civikitchen/php-compatibility` or `civikitchen/php-deprecation` rule.

The 1.45 → 1.55 bump changes formatter output for wrapped boolean
conditions, which now open and close on their own lines, and for wrapped
concatenations on the right of `=`. Repos with such code fail `ckfmt
--check` until one `ckfmt` run. The new output is phpcs-clean.

### 2. Replace phpcs with mago linter rules (done)

`cklint` runs `php -l` and `mago lint` only. civicrm/coder and the
CiviKitchen phpcs standard are gone, and `ckconform` fails on a leftover
`phpcs.xml(.dist)`. `ckmigrate` rewrites a consumer repo's phpcs comments and
deletes its config.

Every check the CiviKitchen standard ran at error level, and what covers it
now. Measured on a corpus of 1182 extension files, both tools over the same
files:

| phpcs check | Now |
|---|---|
| The eight CiviKitchen sniffs, Slevomat `DeclareStrictTypes` | `civikitchen/*` rules, `strict-types` (see Port status) |
| `Drupal.Scope.MethodScope`, `Drupal.Classes.PropertyDeclaration` | `civikitchen/explicit-visibility`; every finding on the same line |
| `Drupal.ControlStructures.InlineControlStructure` | `block-statement`; every finding on the same construct (21) |
| `Drupal.Semantics.FunctionAlias` | `no-alias-function`; every finding on the same line (12) |
| `Drupal.Classes.UnusedUseStatement` (a warning, never gating) | `no-redundant-use`, already gating; mago also reports an unused `ExtensionUtil as E` import, phpcs does not. phpcs counts a name that appears only in docblock prose as used, mago does not |
| Closing tag, `new` without parentheses, line endings, `include` with parentheses, keyword and constant case, tabs, short open tag | `ckfmt` rewrites each of them |
| Every whitespace, indentation, brace and array layout sniff | `ckfmt` |

Dropped on purpose, with no counterpart:

- `else if` against `elseif`, a leading backslash in `use`, function-name
  case: style without a bug behind it.
- Comment layout (`PostStatementComment`, `DocComment.*`, `ClassComment.*`,
  `InlineComment`).
- `ConstructorName`: a PHP 4 constructor is an ordinary method since PHP 8.
- `DeprecatedFunctions`: `ckcompat` owns deprecations.
- `DiscouragedFunctions`: Drupal's devel helpers; `eval` is `no-eval`.
- Drupal-only checks (`t()`, `watchdog`, `hook_menu`, `.info` files,
  `preg_replace` with `/e`).
- `CommentedOutCode`: a warning, which never failed `cklint`.
- A byte-order mark: mago reports it through `strict-types`, since no
  `declare` can follow it.

`// phpcs:ignore` and `phpcs:disable` lines in consumer repos become
`@mago-expect` lines. Leftovers need no check of their own: phpcs no longer
runs, so they are comments, and the mago finding they meant still stands.

### 3. Replace phpstan with mago analyze

- Done: the analyzer baseline in `ckanalyze` — core and civix files as
  includes, the `Result` false positive filtered by a plugin. mago's default
  strictness already reports `mixed` (`mixed-assignment` and friends), which
  phpstan level 10 does not.
- Done: the phpstan rules, the disallowed bans and the phpat architecture
  test as analyzer plugins (see Port status). The bans are a plugin rather
  than `disallowed-functions` settings, because they include static methods
  and a per-path allowance; the architecture test is a plugin rather than
  `mago guard`, because its allowed namespaces come from each repo's
  `info.xml` requirements.
- Shadow phase: `ckanalyze` runs `mago analyze` in `ck ci` after phpstan,
  non-gating (findings report as `shadow`, a crash still fails). It analyzes
  what phpstan's main pass analyzes and reads core, civix files, `vendor/`,
  siblings and `policy.vendored_paths` for their symbols. The `Result` false
  positive is filtered by a plugin. A first run over 38 extension repos
  (2026-10-11) completed on every one; `mixed-assignment` and the other
  `mixed-*` codes make up most findings, followed by classes of required
  extensions that were not checked out beside the repo. Triage every
  difference across the consumer repos into one of: a mago
  false positive (fix the plugin or report upstream), a phpstan-only finding
  (port or accept the loss deliberately), or a real defect (fix it in the
  repo).
- Switch the gate once the shadow run is clean on every consumer repo.
  `phpstan.neon.dist` and `@phpstan-ignore` lines go in the same release,
  and `ckconform` flags leftovers.
- `cklint` then runs `mago analyze` on changed files in place of `php -l`.

### 4. Drop PHPCompatibility (done)

`ckcompat` runs mago alone, with the polyfill directories of CiviCRM core's
`vendor/symfony` as includes:

- at the floor: the `civikitchen/php-compatibility` rule (every `mago lint`
  run also reports the syntax its PHP version cannot parse), and
  `mago analyze` retaining only the `unavailable-*` and `deprecated-*` codes
  for built-in symbols. Analyzer filters drop a deprecation of a
  `@deprecated` symbol in the analyzed code (the static-analysis gate's
  question) and a newer symbol the file guards with `function_exists()`,
  `class_exists()` or `method_exists()`, or with a `PHP_VERSION_ID` or
  `version_compare(PHP_VERSION, …)` check above the floor. A guard counts per
  file, so an unguarded second call in a guarded file goes unreported.
- at the top of `--range`, else 8.5: every rule in mago's Deprecation
  category, read from `mago lint --list-rules` so a bump brings new ones,
  and `civikitchen/php-deprecation`. A closed range thus never fails on a
  deprecation that starts above it.
  mago reports deprecated built-in symbols at every PHP version, so they need
  no second run; the filter drops one whose `#[Deprecated(since: …)]` in
  mago's stubs lies above the top of the range.

A function PHP removed before 8.0 (`each()`, `create_function()`) is not
`ckcompat`'s question: no supported floor has it, and phpstan reports it as
an unknown function.

phpcs, PHPCompatibility and PHPCSUtils left the images with it. Left open:

- A function new in a PHP release that mago's stubs do not mark yet goes
  unreported until a mago bump, or until it joins the rule's list.
- mago 1.55 checks a newer class only on `new`; the
  `civikitchen/unavailable-access` hook adds constant fetches, enum cases,
  static calls and global constants from mago's own version data. That data
  has no version for a newer constant of an older class
  (`ReflectionProperty::IS_VIRTUAL`), so that goes unreported.
- A built-in deprecation marked only by a docblock has no `since`, so a
  `--range` that ends below it still reports it (`lcg_value()` at 8.1-8.3).
- The polyfills are the image core's, so a function core polyfills only since
  a newer CiviCRM than the extension's minimum passes.

## Risks

- The extension SDK appeared in 1.47 and mago releases several times a week.
  Ported rules tie the toolbelt to that API, and every bump runs their
  fixtures.
- A formatter change in a bump turns `ckfmt --check` red on unchanged code.
  The bump procedure in step 1 measures this before a release, and the
  changelog names the one `ckfmt` run consumers need.
- Every consumer repo carries a `phpstan.neon.dist` and suppression lines.
  Steps 2 and 3 migrate them with a `ckconform` check, not by hand.
