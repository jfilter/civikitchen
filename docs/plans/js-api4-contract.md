# Plan: the APIv4 contract and type checking for JavaScript

Status: steps 1–4 and the tests done; typed `CRM.vars` open.

## Where things stand

- **PHP side.** `toolbelt/phpstan` generates `Api4Catalog` from a core source
  tree (entities, actions, field names, completeness per entity) and checks
  `civicrm_api4()` and the fluent form against it. The judgement lives in
  `Api4Contract`: it speaks only when sure — an unknown entity only as a
  near-miss of a core entity, actions only on catalogued entities (plus
  extension action classes), fields only on entities with a complete list,
  never on dotted, starred, aliased or dynamic names.
- **JavaScript side.** `ckeslint` runs oxlint with a baseline config. Plain
  `.js` gets syntactic rules and `no-unsanitized`; the type-aware rules apply
  to `.ts` only. Nothing reads the strings in `CRM.api4()` / `crmApi4()`.
- **Core JavaScript** is untyped (a handful of JSDoc tags) and upstream does
  not want a type layer. It can still be type-checked from outside: oxlint's
  `--type-check` (tsgolint, TypeScript's compiler diagnostics) over the
  unmodified files with `allowJs` + `checkJs` and a declaration file for the
  globals. Measured on the 6.18 image with every CiviCRM global declared as
  `any`: 320 files, 209 diagnostics — misspelt DOM properties, wrong argument
  counts, undeclared names, JSDoc that contradicts the code.

## Why a lint rule and not generated `.d.ts` for APIv4

Encoding the catalog as TypeScript types makes every unknown entity an error.
Extensions call each other's entities, and the PHP rule deliberately reports
an unknown entity only when it reads like a typo of a core one; a type union
cannot express "unknown is fine unless it is close to a known name". The same
holds for the camelCase control params in writes and for select aliases. A
rule that applies `Api4Contract`'s judgement to JavaScript literals keeps both
sides saying the same thing about the same call. It also works on plain `.js`
without a `tsconfig.json`.

## Target

1. **`civikitchen/api4-contract`** — an oxlint JS plugin rule in the image's
   oxlint toolchain, on in both baseline configs. It checks the literal forms
   `CRM.api4(entity, action, params)`, `crmApi4(entity, action, params)` and
   their batch forms (an array or object of `[entity, action, params]`
   tuples), with the judgement of `Api4Contract`: entity near-miss, action,
   and fields in `select`, `where` (nested `AND`/`OR`/`NOT`), `orderBy`,
   `groupBy` and `values`.
2. **The catalog feed.** `ckeslint` exports `Api4Catalog` plus what the repo
   adds — its own entities (`Civi/Api4/*.php`) and action classes
   (`Civi/Api4/Action/<Entity>/*.php`), and the same for checked-out
   siblings in `.civikitchen-siblings/` — to a temporary JSON file and hands
   its path to the rule in `CIVIKITCHEN_API4_CATALOG`. No second generated
   artefact, so no second drift gate: the JSON is derived at run time from
   the catalog the PHP drift gate already keeps current. Without the variable
   the rule throws instead of passing silently.
3. **`ckeslint --core [dir]`** — type-check and lint CiviCRM core's own
   JavaScript. Copies core's first-party `.js` (under `js/`, `ang/`, `ext/`;
   no vendored trees, no minified bundles, no tests) into a temporary
   directory beside a `tsconfig.json` (`allowJs`, `checkJs`, not strict) and
   `civicrm-globals.d.ts` (the globals core's pages load by script tag), runs
   oxlint with `--type-aware --type-check`, the correctness category and
   `api4-contract`, and reports paths relative to core. Default directory is
   `$CIVICRM_CORE_DIR`, then `/var/www/html/core`. This is an analysis tool
   for finding upstream bugs, not a gate; findings go upstream as individual
   bug-fix PRs.

4. **`policy.javascript.type_check`** — the same type check for an
   extension, opt-in so the `@v1` contract does not change for anyone else.
   Through the repo's own `tsconfig.json` when it has one; otherwise as a
   second pass with every lint rule off (`-A all`), over a copy of the tracked
   JS/TS beside the `--core` tsconfig and globals, with the repo's
   `node_modules` linked in, so the normal lint run stays exactly as it was.
   The copy is needed because tsgolint checks
   only files under a tsconfig in their own tree: `--tsconfig` pointing
   elsewhere exits 0 with no output. On the extensions tried, most findings
   were JSDoc the code had outgrown.

## Tests

- **Host, `make test`:** `test-ckeslint` installs `toolbelt/oxlint` from its
  lockfile and runs the real `ckeslint` over a fixture extension whose files
  mark every expected finding inline (`// expect: <text>`), asserting the set
  of reported lines equals the set of marked ones — both directions, so a
  healthy call that is flagged fails as loudly as a typo that is missed. The
  same suite runs `ckeslint --core` over a fixture core tree.
- **Shared PHP:** the catalog export (own entities, action classes, aliases)
  as a unit test under the coverage floor.
- **Image, `tests/images/test-dev-tools.sh`:** one `api4-contract` finding
  through the installed toolchain, so a broken jsPlugins bridge cannot turn
  the rule silently off.

## Not in this round

- **Typed `CRM.vars` / Angular settings.** Needs a booted site to compare the
  declared shape with what PHP injects; a later Playwright scenario.
