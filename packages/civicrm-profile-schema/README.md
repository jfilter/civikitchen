# @jfilter/civicrm-profile-schema

JSON Schema (draft 2020-12) for the civikitchen CiviCRM **profile** format — the
declarative description of a scenario: `dependencies` (extensions), `authx`, and
`apiUsers`. This package is the single source of truth any tool validates these
profiles against.

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
