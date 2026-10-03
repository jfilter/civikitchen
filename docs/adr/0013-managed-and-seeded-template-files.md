# ADR-0013: Template files are either managed or seeded

*Status: accepted · 2026-07-31*

## Context

Every conforming extension repo starts from the template in
`scaffold/template/extension/`. Some of its files must stay identical across
the fleet for the shared pipeline to work: the CI caller, the test bootstraps,
the CI compose stack. Others are the repo's to edit from the first day on,
such as `composer.json` or the phpcs and PHPStan project layers.

## Decision

- `ckinit` sorts every template file into one of two explicit lists.
  **Managed** files (`MANAGED_FILES`) belong to civikitchen: `--update`
  rewrites them and `--check` compares them. **Seeded** files (`SEEDED_FILES`)
  are copied once and then belong to the repo: `ckinit` never overwrites them,
  and only reports or recreates one that is missing.
- A template file in neither list aborts every `ckinit` run.
- Some managed files carry `BEGIN/END CIVIKITCHEN MANAGED` blocks. Only the
  blocks are managed; what a repo writes outside them, such as extra workflow
  inputs or a sibling mount, is its own.
- The CI of every repo runs `ckinit --check` against the template at the
  commit its `@v1` resolved to (ADR-0001), so drift fails the build. The
  caller's `check_template` input turns the check off.
- A repo that must deviate inside a block, or on a managed file without
  blocks, declares the paths with a non-empty reason under
  `policy.template_custom` in `civikitchen.yaml`.

## Rationale

- Copy-once templates drift apart silently. A fix to a workflow or bootstrap
  then has to be ported by hand into every repo, and nobody knows which repos
  still carry the old version.
- Listing seeded files explicitly instead of "everything else": a new template
  file would otherwise default to seeded, copied once and never updated, which
  for a workflow or bootstrap file is exactly the wrong silent default.
- Managed blocks let a repo extend a shared file without forking it.
- A declared, reasoned deviation keeps exceptions visible in one place
  (ADR-0010), instead of a silently edited file.

## Consequences

- A change to a managed file is a change to every consumer's CI. It goes
  through a release and, where a repo has to react, a consumer pass.
- Some files are deliberately seeded although they control CI:
  `phpstan-tests.neon.dist` turns a second gate on by existing, so pushing it
  into existing repos would fail them.
