# Implementation architecture

CiviKitchen has three implementation boundaries:

- **PHP owns structured and reusable logic.** YAML, JSON, XML, ZIP archives,
  policy, version constraints, filesystem rules, and reusable process handling
  live below `toolbelt/lib/php` and are reached through the single `ck` CLI.
- **Shell owns bootstrapping.** Container entrypoints, user switching,
  environment export, pipes, and the final `exec` remain shell where the
  operating system is the API. Shell must not embed PHP programs or implement
  a second parser for a structured format.
- **TypeScript owns browser tests only.** The repository's TypeScript is the
  Playwright suite. Bun remains a supported package-manager choice for
  consuming extensions, not a CiviKitchen implementation dependency.

## Command layout

`toolbelt/bin/ck` is the one executable implementation entrypoint. Command
classes share path discovery, process execution, errors, and output under
`toolbelt/lib/php`. Historical executable names such as `ckprofile`, `ckdeps`,
`ckconform`, `ckscenario`, `cklint`, `ckfmt`, `ckeslint`, `ckcompat`,
`ckphpunit`, `ckcoverage`, `ckmutate`, `cklifecycle`, `ckschemadiff`,
`cksmarty`, `ckcivix`, and `ckrelease` are symlinks to `ck`; they are
compatibility names, not separate programs.

The remaining shell commands are deliberate operating-system adapters:

- `cktaint` invokes the isolated Psalm runtime with its memory and baseline
  flags.
- `ckmodernize` sequences two independent external rewriters, civix and
  Rector.
- `ckcoretest` materializes a sparse external Git checkout into an installed
  core tree before invoking its suite.
- `cktestreset` streams `mysqldump` directly into `mysql` and removes runtime
  cache artifacts.

These commands contain no structured-format parser of their own. If reusable
policy, XML, JSON, archive, file-selection, or validation logic is added to
them, that logic belongs in `toolbelt/lib/php` and is called through `ck`.
Do not replace a shell file with an isolated PHP script: the point of migration
is shared ownership, not a different suffix.

The same boundary applies outside `toolbelt/bin`: image entrypoints, profile
application, `ckcreate`, `ckup`, GitHub workflow adapters, and integration-test
drivers remain shell because they primarily manage processes, users,
containers, pipes, or host ports. Their structured operations call the shared
PHP runtime. Test-only PHP snippets may create fixtures or probe a PHP runtime;
they are test inputs, not duplicated product logic.

## Runtime layout

The image copies `toolbelt/lib` as one unit. `docker/runtime/provision.sh`
retains orchestration that must run as shell, while `ck internal` exposes the
shared PHP operations it needs. Internal commands are not public extension
toolbelt API; their PHP classes are the testable implementation boundary.
