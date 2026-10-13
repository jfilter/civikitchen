# ADR-0011: Checks report only what they are sure of

*Status: accepted · 2026-08-04, extended to the APIv4 contract 2026-09-28*

## Context

The tool belt runs static checks on every push of every extension repo. They
are `ckeslint` with oxlint, `cktaint` with Psalm taint analysis, the APIv4
contract in both languages, and the `ckconform` checks. Each of them has to
choose between catching more and flagging healthy code.

## Decision

A check speaks only where it is sure. A false alarm on healthy code counts as a
defect of the check, not as a nuisance for the repo.

`ckeslint`'s baseline is not a style guide. It consists of oxlint's
correctness, suspicious and perf categories and its promise plugin, all of
which catch mistakes rather than fashion. It adds no-unsanitized, and the
type-aware TypeScript rules only where the repo has a `tsconfig.json`.
`ckeslint --core` runs the correctness category alone.

`cktaint`'s stubs are deliberately small. They model only what Psalm cannot
see, which is CiviCRM core and the PSR-7 and Guzzle classes in `vendor/`. The
safe halves of an API are not sinks. These are the `$params` of executeQuery,
the interpolation arrays of CRM_Utils_SQL_Select and Guzzle's `$options`.
Hooks, APIv4 parameters and Smarty assignments are not sources.

Api4Contract reports an unknown entity only when it is a near-miss of a known
one. It reports fields only on entities whose field list is complete
(ADR-0008).

## Rationale

A report where everything is flagged is a report nobody reads. Tainting every
hook parameter, or every unknown entity name, would bury the one real finding.

Flagging the safe path punishes exactly the code that the extension standard
asks for. Examples are an executeQuery placeholder or an
`if ($request->getMethod() !== 'POST')` guard.

An XSS in an extension is an XSS on every site that installs it. That is why
no-unsanitized is in the baseline even though it is a narrow rule.

## Consequences

Some real defects pass. Anything that leaves PHP and comes back through a
hook, the API kernel or a Smarty template is invisible to `cktaint`.

A fix to a check ships with the fixture that would have failed. The
`ckeslint` fixture marks every expected finding inline and also fails on an
unmarked one. A false alarm therefore fails as loudly as a missed finding.
