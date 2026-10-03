# ADR-0008: The APIv4 contract in JavaScript is a lint rule, not generated types

*Status: accepted · 2026-09-28*

## Context

On the PHP side, `toolbelt/phpstan` generates `Api4Catalog` from a core source
tree (entities, actions, field names, and whether an entity's field list is
complete) and checks `civicrm_api4()` and the fluent form against it. The
judgement lives in `Api4Contract`: it reports an unknown entity only as a
near-miss of a core entity, actions only on catalogued entities, and fields
only on entities with a complete list, never on dotted, starred, aliased or
dynamic names. Nothing read the strings in JavaScript's `CRM.api4()` and
`crmApi4()`.

## Decision

- `civikitchen/api4-contract` is an oxlint JS plugin rule in the image's oxlint
  toolchain, on in both baseline configs. It checks the literal forms
  `CRM.api4(entity, action, params)`, `crmApi4(…)` and their batch forms with
  the judgement of `Api4Contract`: entity near-miss, action, and the fields in
  `select`, `where` (nested `AND`/`OR`/`NOT`), `orderBy`, `groupBy` and
  `values`.
- `ckeslint` exports `Api4Catalog` plus the repo's own entities and action
  classes, and those of checked-out siblings, to a temporary JSON file. It
  hands the file's path to the rule in `CIVIKITCHEN_API4_CATALOG`. Without the
  variable, the rule throws instead of passing silently.

## Rationale

- Encoding the catalog as TypeScript types makes every unknown entity an
  error. Extensions call each other's entities; a type union cannot express
  "unknown is fine unless it is close to a known name". The same holds for the
  camelCase control params in writes and for select aliases.
- One judgement applied to both languages keeps PHP and JavaScript saying the
  same thing about the same call.
- A lint rule also works on plain `.js` without a `tsconfig.json`.
- The JSON is derived at run time from the catalog the PHP drift gate already
  keeps current, so there is no second generated artefact and no second drift
  gate.

## Consequences

- Only literal arguments are checked; a call built from variables is not.
- The image test runs one `api4-contract` finding through the installed
  toolchain, so a broken plugin bridge cannot switch the rule off silently.
