# ADR-0012: Upgrade fixtures are scripts against the live site database

*Status: accepted · 2026-07-31*

## Context

The upgrade job boots an older CiviCRM with the extension, upgrades core, and
asserts that the extension and its data survived. A repo can add its own
fixture. It consists of a seed that runs before the upgrade and an assertion
that runs after it.

## Decision

The repo's fixtures are plain PHP scripts, `tests/upgrade/seed.php` and
`tests/upgrade/assert.php`. They run with `cv scr` against the site. They are
not a PHPUnit class.

## Rationale

These assertions have to run against the live site database. The headless
test harness points `CIVICRM_DSN` at the isolated `civicrm_test` scratch
database instead. A fixture based on PHPUnit would pass while testing a
database that the upgrade never touched.

## Consequences

Without the two scripts the job still runs its four built-in assertions, but it
says nothing about the repo's own data. The log says so.

The fixtures do not get PHPUnit's assertions or reporting. A failure is an
exception and a non-zero exit.
