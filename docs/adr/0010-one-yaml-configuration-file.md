# ADR-0010: One configuration file, YAML only

*Status: accepted · 2026-08-30*

## Context

An extension repo configures CiviKitchen in two areas. The first is repository
and toolbelt policy, such as the licence, the coverage floor, release rules
and exceptions. The second is the local or CI scenario, such as the image,
database, locale, profile selection, mounts and checks.

## Decision

`civikitchen.yaml` is the single CiviKitchen configuration of an extension and
holds both areas. Profile definitions stay in their own `profile.json`, and
the config selects them by name.

The file is parsed with the pinned Symfony YAML component and validated
against the published JSON Schema. JSON input is rejected.

Each part has one parser. The scenario parser behind `ck scenario` and
`ck config` reads the file, and Policy is the single parser of its `policy:`
section. Shell tools read policy only through `ckconform --policy-env` or
`ckconform --policy <key>`. A new public key goes into the JSON Schema. It
also goes into `Policy::KEYS` if a consumer reads its normalized value.

## Rationale

One canonical filename and syntax works identically on laptops and in CI.
Accepting a second syntax would double every lookup and every error message.

Every per-repo exception lives in the same file together with its reason.
That covers template deviations, coverage floors, dist exclusions and untagged
versions, so a repo's deviations can be read in one place.

## Consequences

A configuration is declaratively repeatable but not artifact-identical.
Moving image tags and registry downloads change underneath it. Byte-for-byte
reproduction needs digest-pinned images and commit-pinned dependencies.
