# ADR-0015: PHP for logic, shell for bootstrapping, TypeScript for browser tests

*Status: accepted · 2026-08-31*

## Context

The tool belt mixes shell scripts, PHP, a Playwright suite and an oxlint
plugin. Without a rule
for which language does what, structured formats end up parsed in shell, and
the same format is read in several places, each a little differently.

## Decision

- **PHP owns structured and reusable logic**: YAML, JSON, XML, ZIP archives,
  policy, version constraints, filesystem rules and process handling. The
  shared code lives below `toolbelt/lib/php` and is reached through the `ck`
  CLI; `ckconform` and `scaffold/ckinit.php` are PHP programs of their own.
- **Shell owns bootstrapping**: container entrypoints, user switching,
  environment export, pipes and the final `exec`, where the operating system
  is the API. Shell does not embed PHP programs or implement a second parser
  for a structured format.
- **TypeScript owns browser tests only**: the repository's TypeScript is the
  Playwright suite. JavaScript appears only where a tool demands it, such as
  the oxlint plugin that carries the APIv4 contract rule (ADR-0008).

## Rationale

- PHP is already in every image, so structured logic costs no extra runtime,
  and it can be unit-tested with the coverage floor the repo applies to itself.
- One parser per format: `sed` or `grep -o` on a structured file breaks on the
  first valid input it did not expect, and two parsers disagree on edge cases.
- Shell stays where it is the natural tool, and only there.

## Consequences

- Bun remains a supported package-manager choice for consuming extensions, but
  is not a dependency of CiviKitchen itself.
- New tools that need structured input are written as `ck` subcommands, not
  as shell scripts.
