# @jfilter/civicrm-profile-schema

JSON Schema (draft 2020-12) for the civikitchen CiviCRM **profile** format — the
declarative description of a scenario: `dependencies` (extensions), `authx`,
`apiUsers`, and declarative content (`config` + `seed`). This package is the
single source of truth any tool validates these profiles against.

Reusable `$defs` for consumers that only need a slice:

- `#/$defs/dependency` — one extension entry (an extension manifest).
- `#/$defs/contentProfile` — a standalone `config` + `seed` (+ optional
  `scripts`) document (e.g. a seeding profile). A full profile embeds the same
  shape inline.

Content has two tiers: **declarative** `config` (asserted every run) + `seed`
(created-once fixtures) for config and anchors, and optional **imperative**
`scripts` (PHP run via `cv scr`, after the declarative tier) for volume /
complex data that declarative YAML can't express. Nothing has to be declarative.

## Use

```ts
import schema from "@jfilter/civicrm-profile-schema/profile.schema.json";
import Ajv2020 from "ajv/dist/2020";
import addFormats from "ajv-formats";

const ajv = new Ajv2020({ allErrors: true });
addFormats(ajv);
ajv.addSchema(schema);

// whole profile:
const validateProfile = ajv.getSchema(schema.$id);
// just one extension dependency (e.g. an extension manifest entry):
const validateDependency = ajv.getSchema(`${schema.$id}#/$defs/dependency`);
```

Published to GitHub Packages under the `@jfilter` scope. Installing requires a
`read:packages` token (GitHub Packages has no anonymous npm access, even for
public packages) — put it in your user `~/.npmrc`:

```
//npm.pkg.github.com/:_authToken=<PAT with read:packages>
```

and route the scope in the consuming repo's `.npmrc`:

```
@jfilter:registry=https://npm.pkg.github.com
```
