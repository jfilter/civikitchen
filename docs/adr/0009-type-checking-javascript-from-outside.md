# ADR-0009: Type-check JavaScript from outside, opt-in

*Status: accepted · 2026-09-28*

## Context

CiviCRM core's JavaScript is untyped apart from a handful of JSDoc tags, and
upstream does not want a type layer. TypeScript can still check unmodified
`.js` files with allowJs and checkJs and a declaration file for the globals.
We measured this on the 6.18 image with every CiviCRM global declared as any.
Over 320 files it produced 209 diagnostics. Among them were misspelt DOM
properties, wrong argument counts, undeclared names and JSDoc that contradicts
the code.

## Decision

`ckeslint --core [dir]` type-checks and lints core's own JavaScript. It copies
core's first-party `.js` files, leaving out vendored trees, minified bundles
and tests. It places them beside a non-strict `tsconfig.json` and a
declaration file for the globals that core's pages load by script tag. It then
runs oxlint with `--type-aware --type-check`, the correctness category and
`api4-contract`. The directory defaults to `$CIVICRM_CORE_DIR`, and after that
to `/var/www/html/core`.

`--core` is an analysis tool for finding upstream bugs, not a gate. Its
findings go upstream as individual bug-fix PRs.

For an extension, `policy.javascript.type_check: true` runs the same type
check. A repo with its own `tsconfig.json` is checked through that file.
Otherwise a second pass runs with every lint rule off. It works on a copy of
the tracked JS and TS files placed beside the `--core` tsconfig and globals,
with the repo's `node_modules` linked in.

## Rationale

Making it opt-in keeps the `@v1` contract unchanged for every repo that does
not ask for it (ADR-0001).

The copy is needed because tsgolint checks only files under a tsconfig in
their own tree. Pointing `--tsconfig` elsewhere exits 0 with no output.

A separate pass leaves the normal lint run exactly as it was.

## Consequences

On the extensions we tried, most findings were JSDoc that the code had
outgrown.

Typed `CRM.vars` and Angular settings are not covered. Comparing the declared
shape with what PHP injects would need a booted site.
