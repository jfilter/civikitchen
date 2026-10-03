# ADR-0011: Checks report only what they are sure of

*Status: accepted · 2026-08-04, extended to the APIv4 contract 2026-09-28*

## Context

The tool belt runs static checks on every push of every extension repo:
`ckeslint` (oxlint), `cktaint` (Psalm taint analysis), the APIv4 contract on
both languages, and the `ckconform` checks. Each one chooses between catching
more and flagging healthy code.

## Decision

A check speaks only where it is sure. A false alarm on healthy code counts as
a defect of the check, not as a nuisance for the repo.

- **`ckeslint`'s baseline is not a style guide.** It is oxlint's
  `correctness`, `suspicious` and `perf` categories and its `promise` plugin
  (mistakes, not fashion), `no-unsanitized`, and the type-aware TypeScript
  rules only where the repo has a `tsconfig.json`. `ckeslint --core` runs
  `correctness` alone.
- **`cktaint`'s stubs are deliberately small.** They model only what Psalm
  cannot see (CiviCRM core and the PSR-7/Guzzle classes in `vendor/`), and
  the safe halves of an API are not sinks: the `$params` of `executeQuery`,
  the interpolation arrays of `CRM_Utils_SQL_Select`, and Guzzle's `$options`.
  Hooks, APIv4 parameters and Smarty assignments are not sources.
- **`Api4Contract`** reports an unknown entity only as a near-miss of a known
  one, and fields only on entities whose field list is complete (ADR-0008).

## Rationale

- A report where everything is flagged is a report nobody reads. Tainting
  every hook parameter, or every unknown entity name, would bury the one real
  finding.
- Flagging the safe path, such as an `executeQuery` placeholder or an
  `if ($request->getMethod() !== 'POST')` guard, punishes exactly the code the
  extension standard asks for.
- An XSS in an extension is an XSS on every site that installs it, so
  `no-unsanitized` is in the baseline despite being a narrow rule.

## Consequences

- Some real defects pass: anything that leaves PHP and comes back through a
  hook, the API kernel or a Smarty template is invisible to `cktaint`.
- A fix to a check ships with the fixture that would have failed. The
  `ckeslint` fixture marks every expected finding inline and fails on an
  unmarked one too, so a false alarm fails as loudly as a missed finding.
