# ADR-0013: Template files are either managed or seeded

*Status: accepted · 2026-07-31*

## Context

Every conforming extension repo starts from the template in
`scaffold/template/extension/`. Some of its files must stay identical across
the fleet for the shared pipeline to work. Examples are the CI caller, the
test bootstraps and the CI compose stack. Other files are the repo's to edit
from the first day on, such as `composer.json` or the PHPStan project layer.

## Decision

`ckinit` sorts every template file into one of two explicit lists. Managed
files, listed in MANAGED_FILES, belong to civikitchen. `--update` rewrites
them and `--check` compares them. Seeded files, listed in SEEDED_FILES, are
copied once and then belong to the repo. `ckinit` never overwrites them. It
only reports or recreates one that is missing.

A template file that is in neither list aborts every `ckinit` run.

Some managed files carry `BEGIN/END CIVIKITCHEN MANAGED` blocks. In those
files only the blocks are managed. What a repo writes outside them is its own,
for example extra workflow inputs or a sibling mount.

The CI of every repo runs `ckinit --check` against the template at the commit
that its `@v1` resolved to (ADR-0001), so drift fails the build. The caller's
`check_template` input turns the check off.

A repo that must deviate inside a block, or on a managed file without blocks,
declares the paths under `policy.template_custom` in `civikitchen.yaml`. Each
path needs a non-empty reason.

## Rationale

Templates that are copied once drift apart silently. A fix to a workflow or
bootstrap then has to be ported by hand into every repo, and nobody knows
which repos still carry the old version.

Seeded files are listed explicitly rather than defined as everything else.
Otherwise a new template file would default to seeded, copied once and never
updated. For a workflow or bootstrap file that is exactly the wrong silent
default.

Managed blocks let a repo extend a shared file without forking it.

A declared deviation with a reason keeps exceptions visible in one place
(ADR-0010), instead of hiding them in a silently edited file.

## Consequences

A change to a managed file is a change to every consumer's CI. It goes through
a release and, where a repo has to react, a consumer pass.

Some files are deliberately seeded although they control CI.
`phpstan-tests.neon.dist` turns a second gate on by existing, so pushing it
into existing repos would fail them.
