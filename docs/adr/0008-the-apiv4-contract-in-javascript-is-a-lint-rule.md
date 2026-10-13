# ADR-0008: The APIv4 contract in JavaScript is a lint rule, not generated types

*Status: accepted · 2026-09-28*

## Context

On the PHP side, `toolbelt/phpstan` generates Api4Catalog from a core source
tree. The catalog records entities, actions, field names, and whether an
entity's field list is complete. The PHP rules check `civicrm_api4()` and the
fluent form against it.

The judgement lives in Api4Contract. It reports an unknown entity only when the
name is a near-miss of a core entity. It checks actions only on catalogued
entities, and fields only on entities with a complete list. It never reports
dotted, starred, aliased or dynamic names. Nothing read the strings that
JavaScript passes to `CRM.api4()` and `crmApi4()`.

## Decision

`civikitchen/api4-contract` is an oxlint JS plugin rule in the image's oxlint
toolchain. It is on in both baseline configs. It checks the literal forms
`CRM.api4(entity, action, params)` and `crmApi4(…)` and their batch forms. It
applies the same judgement as Api4Contract to the entity near-miss, to the
action and to the fields. Fields are read from select, where (including nested
AND, OR and NOT), orderBy, groupBy and values.

`ckeslint` exports Api4Catalog to a temporary JSON file. The file also holds
the repo's own entities and action classes, and those of checked-out
siblings. `ckeslint` hands the file's path to the rule in
`CIVIKITCHEN_API4_CATALOG`. Without that variable, the rule throws instead of
passing silently.

## Rationale

Encoding the catalog as TypeScript types would make every unknown entity an
error. Extensions call each other's entities, and a type union cannot express
that an unknown name is fine unless it is close to a known one. The same holds
for the camelCase control params in writes and for select aliases.

Applying one judgement in both languages keeps PHP and JavaScript saying the
same thing about the same call.

A lint rule also works on plain `.js` files without a `tsconfig.json`.

The JSON is derived at run time from the catalog that the PHP drift gate
already keeps current. There is no second generated artefact and no second
drift gate.

## Consequences

Only literal arguments are checked. A call built from variables is not.

The image test runs one `api4-contract` finding through the installed
toolchain. A broken plugin bridge therefore cannot switch the rule off
silently.
