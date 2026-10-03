# ADR-0012: Upgrade fixtures are scripts against the live site database

*Status: accepted · 2026-07-31*

## Context

The upgrade job boots an older CiviCRM with the extension, upgrades core, and
asserts that the extension and its data survived. A repo can add its own
fixture: a seed run before the upgrade and an assertion run after it.

## Decision

The repo's fixtures are plain PHP scripts, `tests/upgrade/seed.php` and
`tests/upgrade/assert.php`, run with `cv scr` against the site, not a PHPUnit
class.

## Rationale

These assertions have to run against the live site database, and the headless
test harness points `CIVICRM_DSN` at the isolated `civicrm_test` scratch
database. A PHPUnit-based fixture would pass while testing a database the
upgrade never touched.

## Consequences

- Without the two scripts the job still runs its four built-in assertions, but
  says nothing about the repo's own data; the log says so.
- The fixtures do not get PHPUnit's assertions or reporting; a failure is an
  exception and a non-zero exit.
