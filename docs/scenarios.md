# Declarative scenarios

`civikitchen.yaml` is the single CiviKitchen configuration for an extension:
repository/toolbelt policy plus the local or CI scenario. Profile definitions
remain in their separate `profile.json`; the config selects them by name.
CiviKitchen parses it with
the pinned Symfony YAML component and then validates the resulting document
against the published JSON Schema. JSON input is deliberately rejected so one
canonical filename and syntax works identically on laptops and in CI.

```yaml
"$schema": https://raw.githubusercontent.com/jfilter/civikitchen/main/packages/civikitchen-scenario-schema/scenario.schema.json
version: 1
policy:
  license: AGPL-3.0-or-later
  coverage:
    minimum: 80
  mutation:
    minimum_msi: 60
    paths:
      - Civi
  vendored_paths:
    - path: resources/vendor
      reason: Unmodified upstream assets
scenario:
  name: myextension
  image: ghcr.io/jfilter/civikitchen:v1
  database:
    image: mariadb:11.4
  extension:
    key: myextension
    path: .
    writable: false
  locales:
    - de_DE
  default_locale: de_DE
  profiles:
    - verein
  profile_paths:
    - ./profiles
  trust_external_profiles: true
  credentials_output: file
  checks:
    - conform
    - lint
    - format
    - test
    - lifecycle
```

Validate and inspect the normalized plan:

```bash
ck config validate
ck scenario plan
ck scenario commands
```

Render a Docker Compose model and boot it:

```bash
ck scenario compose > civikitchen.compose.json
# or write it directly:
ck scenario materialize civikitchen.yaml civikitchen.compose.json
docker compose -f civikitchen.compose.json up -d --wait
```

Compose JSON is intentional: Docker Compose accepts it as the JSON form of the
Compose model, and the generator can use a real JSON encoder. Relative extension
and profile paths are resolved against the scenario file and emitted as absolute
mount sources, so a materialized model remains correct in another directory.
The source is mounted at `/civikitchen-extension`; each image links that stable
mount into its own CiviCRM-discovered extension directory, so the same scenario
model works across Standalone, Drupal, WordPress and Joomla image layouts.
The extension directory must contain `info.xml`, and `extension.key` must match
the root `<extension key="…">` attribute (not the usually shorter `<file>`
prefix). Bind mounts are read-only by default; `extension.writable` is an
explicit opt-in. An extension path outside the config directory additionally
requires `trust_external_extension: true`. External profile paths likewise
require `trust_external_profiles: true` because
their drivers and seeds execute as trusted application code.

`credentials_output: log` or `both` requires `allow_secret_logging: true`, so a
committed config cannot begin disclosing generated credentials accidentally.

When profiles are selected, the generator creates the Standalone `admin` demo
user needed by the shared profile driver. If `site_url` is omitted it is derived
from `http_port`; ports outside `1..65535` are rejected before Compose render.

The `checks` list is an enum of CiviKitchen operations. It cannot contain shell
source; `ck scenario commands` emits only fixed `ck` invocations. The generated
disposable stack uses the database admin account so CiviKitchen can create its
isolated headless scratch database without materializing a separate grant file.

The document is declaratively repeatable, but moving image tags and runtime
downloads are not artifact-identical. Pin both application and database images
by digest. Bundled profiles pin Git sources to full commits; a profile that uses
`registry: true` still resolves the registry's current packaged release at first
boot because CiviCRM's registry interface exposes no artifact digest in this
format. For byte-for-byte reproduction, use only commit-pinned dependencies (or
materialize a separately checksummed package source) and avoid registry-backed
profile dependencies. Convenient image tags such as `:v1` deliberately follow
maintained updates.
