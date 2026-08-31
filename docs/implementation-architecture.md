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
`ckconform`, and `ckscenario` are symlinks to `ck`; they are compatibility
names, not separate programs.

Commands not migrated yet are dispatched to their existing executable. Move
them behind the shared PHP application incrementally, with the existing CLI
contract and focused regression tests held constant. Do not replace a shell
file with an isolated PHP script: the point of migration is shared ownership,
not a different suffix.

## Runtime layout

The image copies `toolbelt/lib` as one unit. `docker/runtime/provision.sh`
retains orchestration that must run as shell, while `ck internal` exposes the
shared PHP operations it needs. Internal commands are not public extension
toolbelt API; their PHP classes are the testable implementation boundary.
