# ADR-0017: PHP quality tools consolidate on mago

*Status: accepted · 2026-10-11*

## Context

The images ship nine PHP quality tools. They are php -l, phpcs with
civicrm/coder, Slevomat and PHPCompatibility, phpstan with four extensions,
psalm, rector, infection, composer-dependency-analyser and mago. Each one
brings its own composer root, lock file, pin and configuration format, and
several of them answer the same question.

mago covers formatting, linting, static analysis and architecture rules in one
binary. Since 1.47 it also loads extensions written in PHP, as linter rules
and analyzer plugins, so CiviKitchen's own checks can move with it.

## Decision

Every job that mago can do moves onto mago, and the tool that did it leaves the
images.

A tool leaves only when mago is shown to find what the tool found. The proof
is fixtures and a run over real extension code, recorded in
[Consolidating on mago](../mago-consolidation.md). A known gap keeps the tool
until upstream or a CiviKitchen rule closes it.

psalm for taint analysis, rector for refactoring, infection for mutation
testing and composer-dependency-analyser stay, because mago has no
equivalent.

The mago pin follows the newest release. Each bump re-derives the checksums.
It also checks that formatter output stays clean under `cklint` and that
linting reports nothing new on the extension repos.

## Rationale

One engine should answer one question. A formatter and a linter that come from
the same parser cannot contradict each other on layout.

On the extension code we measured, mago analysis ran four times faster than
phpstan and found a real defect that phpstan missed.

Fewer composer roots mean fewer lock files to keep current, a smaller image
and fewer transitive packages in the image scan.

Proof before removal keeps a gate from going quietly blind. mago does not yet
report everything that PHPCompatibility reports.

## Consequences

CiviKitchen's phpcs sniffs and phpstan rules have to be ported to the mago
extension API. That API is young, so every bump runs their fixtures.

Following the newest release brings formatter changes. A bump that changes
output tells consumers in the changelog to run `ckfmt` once.

Consumer repos migrate their `phpstan.neon.dist` and their phpcs and phpstan
suppressions. `ckconform` reports what is left over.
