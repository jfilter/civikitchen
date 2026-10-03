# ADR-0010: One configuration file, YAML only

*Status: accepted · 2026-08-30*

## Context

An extension repo configures CiviKitchen in two areas: repository and toolbelt
policy (licence, coverage floor, release rules, exceptions), and the local or
CI scenario (image, database, locale, profile selection, mounts, checks).

## Decision

- `civikitchen.yaml` is the single CiviKitchen configuration of an extension
  and holds both. Profile definitions stay in their own `profile.json`; the
  config selects them by name.
- It is parsed with the pinned Symfony YAML component and validated against
  the published JSON Schema. JSON input is rejected.
- One parser per part: the scenario parser (`ck scenario`, `ck config`)
  reads the file, and `Policy` is the single parser of its `policy:` section.
  Shell tools read policy only through `ckconform --policy-env` /
  `--policy <key>`. A new public key goes into the JSON Schema, and into
  `Policy::KEYS` if a consumer reads its normalized value.

## Rationale

- One canonical filename and syntax works identically on laptops and in CI;
  accepting a second syntax doubles every lookup and every error message.
- Every per-repo exception (template deviations, coverage floors, dist
  exclusions, untagged versions) lives in the same file with its reason, so a
  repo's deviations can be read in one place.

## Consequences

- A configuration is declaratively repeatable, but not artifact-identical:
  moving image tags and registry downloads change underneath it. Byte-for-byte
  reproduction needs digest-pinned images and commit-pinned dependencies.
