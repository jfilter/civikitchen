# ADR-0009: Type-check JavaScript from outside, opt-in

*Status: accepted · 2026-09-28*

## Context

CiviCRM core's JavaScript is untyped apart from a handful of JSDoc tags, and
upstream does not want a type layer. TypeScript can still check unmodified
`.js` with `allowJs` and `checkJs` and a declaration file for the globals.
Measured on the 6.18 image with every CiviCRM global declared as `any`: 320
files, 209 diagnostics, among them misspelt DOM properties, wrong argument
counts, undeclared names and JSDoc that contradicts the code.

## Decision

- `ckeslint --core [dir]` type-checks and lints core's own JavaScript. It
  copies core's first-party `.js` (no vendored trees, minified bundles or
  tests) beside a non-strict `tsconfig.json` and a declaration file for the
  globals core's pages load by script tag, then runs oxlint with
  `--type-aware --type-check`, the correctness category and `api4-contract`.
  The directory defaults to `$CIVICRM_CORE_DIR`, then `/var/www/html/core`.
- `--core` is an analysis tool for finding upstream bugs, not a gate. Its
  findings go upstream as individual bug-fix PRs.
- For an extension, `policy.javascript.type_check: true` runs the same type
  check. A repo with its own `tsconfig.json` is checked through it. Otherwise a
  second pass runs with every lint rule off, over a copy of the tracked JS/TS
  beside the `--core` tsconfig and globals, with the repo's `node_modules`
  linked in.

## Rationale

- Opt-in keeps the `@v1` contract unchanged for every repo that does not ask
  for it (ADR-0001).
- The copy is needed because tsgolint checks only files under a tsconfig in
  their own tree: `--tsconfig` pointing elsewhere exits 0 with no output.
- A separate pass leaves the normal lint run exactly as it was.

## Consequences

- On the extensions tried, most findings were JSDoc the code had outgrown.
- Typed `CRM.vars` and Angular settings are not covered: comparing the
  declared shape with what PHP injects needs a booted site.
