# Extension standards: what a modern extension looks like

This is the checklist the civikitchen tooling enforces or expects: cklint with
the CiviKitchen mago baseline, ckmodernize, phpstan and the extension template.
Use it for audits, and as the target state when you modernize an existing
extension.

ckcreate and ckinit apply the extension template, which sets most of this
up. See [Creating an extension](extension-development.md#creating-an-extension).

## UI: declarative before imperative

New screens use the declarative building blocks core provides:

- Listings and reports are a managed SearchKit SavedSearch with a
  SearchDisplay, not a `CRM_Core_Page` or `CRM_Report_Form` with Smarty.
  Custom reports are deprecated in core.
- Forms are Afform/FormBuilder (`ang/*.aff.html`), not QuickForm
  (`CRM_Core_Form`). Custom handling goes through `hook_civicrm_afformSubmit`
  or APIv4 actions.
- Data endpoints are APIv4 actions, not page callbacks.

The phpat rule `testNoLegacyUiBases` enforces this. It lives in
`civikitchen/phpstan-ck-legacy` and is registered automatically in every
phpstan run. The rule resolves the full class ancestry, so it also catches an
extension's own intermediate base class or a concrete core report subclass.
The retired `CiviKitchen.Legacy.NoLegacyPageForm` sniff only saw the direct
`extends`. Audits should still grep for `.tpl` templates and page routes.

Some cases are legitimate exceptions. Raw callback endpoints and iframe hosts
are one. Third-party framework bases that mandate QuickForm are another, such
as CiviRules' `CRM_CivirulesActions_Form_Form`. These, and grandfathered
screens, get an `ignoreErrors` entry for `phpat.testNoLegacyUiBases` in
`phpstan.neon.dist`, with a reason.

Core declares the `permission` key in `*.aff.json` as `data_type => Array`
(in `Civi\Api4\Afform`), and all 36 afforms core ships use the list form
`["access foo"]`. Prefer that form. A plain string is tolerated but not
canonical. Core silently splits it with `explode(',')`, so a permission name
that contains a comma becomes two.

Theming uses Riverlea's `--crm-*` custom properties, under the names core
declares in `ext/riverlea/core/css/_variables.css` and in each stream's
`_dark.css`. When a name is wrong, the fallback in `var(--crm-x, #hex)` renders
in every theme. The ckconform check `riverlea-custom-property` therefore
fails on any `--crm-*` name that neither core nor the repository declares. A
name that only some streams declare, or that only the stream editor sets,
passes as part of the theme.

## Code

### APIv3 is banned

Use APIv4 only, through `civicrm_api4()` or the object-oriented builders, and
never `civicrm_api3()`. phpstan enforces this, not the linter. The bans live
in `/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon`, built
on spaze/phpstan-disallowed-calls, and the template's `phpstan.neon.dist`
includes that file. The same file bans removed or deprecated core helpers:
`CRM_Utils_Array::value()`, `CRM_Core_Error::fatal()` and the
`CRM_Core_Error::debug_*()` family. Because phpstan resolves types, an
indirect `$class::value()` is caught too. An exception is an `allowIn` or
`allowInMethods` entry in the repo's own `phpstan.neon.dist`, with the reason
next to it.

phpstan reads only PHP, so ckconform also rejects `CRM.api3` in JavaScript
and Smarty. Annotate a genuine exception there with
`ck-allow-api3 -- <reason>`.

An extension's own `civicrm_api3_<entity>_<action>()` export raises the
ckconform warning `api3-surface`. A scheduled job does not need one.
`CRM_Core_BAO_Job::parseParameters()` forces version 3 only for `key=value`
parameters and passes JSON parameters through. A managed job with
`'parameters' => '{"version":4,"checkPermissions":false}'` therefore calls the
APIv4 action directly. Keep an APIv3 export only for a documented external
compatibility contract, and mark it with
`ckconform-ignore api3-surface -- <reason>`.

### APIv4 entities must exist in the supported core

Entities resolve at runtime. A reference to `\Civi\Api4\Foo` compiles, passes
phpstan and passes every test that never loads that page, and then fatals in
production. Check the entity's `@since` against `<compatibility><ver>`. Core
also ships entities from bundled extensions such as `ext/civi_mail`, and
those extensions then belong in `<requires>`.

ckconform verifies that each referenced entity exists in the core it runs
against. An entity supplied by a required third-party extension is declared
narrowly under `policy.known_api4_entities` in `civikitchen.yaml`, with
`entities: [ExternalEntity]` and a reason naming the required provider.
Unused entries and declarations without a provider fail.

### The strings in an APIv4 call are checked

Entity, action and field names are string literals that nothing checks until
the call runs. A typo in a select survives the whole pipeline and then returns
the wrong data on a customer's site. The rules in
`civikitchen/phpstan-ck-legacy` check `civicrm_api4()` and the fluent form
against `Api4Catalog`, which is generated from the pinned core. They report:

- `ck.api4.unknownEntity`, only when the name is a near-miss of a real entity.
  Other extensions' entities are not in the catalog and must not be flagged.
- `ck.api4.unknownAction`.
- `ck.api4.unknownField` in `select`, `where`, `orderBy`, `groupBy` and
  `values`.

Field names are judged only for the record actions: `get`, `create`,
`update`, `save`, `delete` and `replace`. `getFields`, `getActions` and custom
actions filter rows of a different shape. Action names compare
case-insensitively, because APIv4 resolves them through PHP method names.
Actions arrive through class inheritance and through traits, and the catalog
follows both. `Generic\Traits\ManagedEntity`, for example, gives some twenty
entities `export()` and `revert()`.

Everything the source tree cannot settle is skipped silently:

- non-literal names
- dotted names, such as joins, `custom.*` and price-set fields
- SQL expressions and their aliases
- `segment_*` fields from SearchKit
- camelCase BAO pass-through params in a write
- every entity whose field list is not table-backed: Setting, Afform, ECK,
  `SK_*` and `Custom_*`

A finding is therefore worth reading rather than baselining. A builder held in
a variable, as in `$q = Contact::get(); $q->addSelect(…)`, is judged through
its type, which names the entity exactly. Only `orderBy` and `groupBy` are
skipped there, because an alias defined by an earlier link is out of sight.

ckeslint applies the same contract to JavaScript. Its
`civikitchen/api4-contract` rule reads the literal forms
`CRM.api4(entity, action, params)` and `crmApi4(entity, action, params)`, plus
their batch forms, which pass an array or object of `[entity, action, params]`
tuples. It judges them the same way as the phpstan rules
([ADR-0008](adr/0008-the-apiv4-contract-in-javascript-is-a-lint-rule.md)). Its
data comes from the same `Api4Catalog`, plus the repo's own
`Civi/Api4/*.php` entities and `Civi/Api4/Action/<Entity>/*.php` action
classes, and those of checked-out siblings in `.civikitchen-siblings/`. The
rule runs in the baseline config only. A repo with its own `.oxlintrc.json`
does not get it.

The right-hand side of an implicit join is checked too. The catalog carries a
join map, `Api4Catalog::JOINS`. Every field with an `entity_reference` becomes
a joinable of the same name (`SchemaMapBuilder::addJoins`), plus the eight
primary and billing links that `ContactSchemaMapSubscriber` adds to Contact.
In `addSelect('address_primary.street_address')` the left side resolves to
Address, and the right side is checked against Address's fields. The correct
form of the address-fields-on-Contact bug now gets the same scrutiny as the
wrong one, and `address_primary.no_such_field` is reported.

The join check stays silent wherever the source tree cannot decide:

- a left side the map does not know, such as an explicit
  `->addJoin('Address AS x')` alias, a custom-group name or another
  extension's entity
- a name that an explicit join rebound
- a multi-level path like `a.b.c`
- a builder held in a variable, where the joins an earlier link declared are
  invisible
- a target entity whose own field list is not table-backed

Writes through `addValue` or `setValues` are out of scope, because a join is
not a write target.

### Parameters of custom APIv4 actions

A protected property on a custom APIv4 action is an API parameter, and the
kernel fills it from the caller's params. If the property is typed,
non-nullable and has no default, a caller who omits it does not get an API
validation error. They get PHP's "must not be accessed before
initialization", from inside the kernel, naming no field. The rule
`ck.api4.uninitializedActionParam` reports this.

`@required` does not rescue that form. This is the counter-intuitive part,
and it was verified in a running install.
`ValidateFieldsSubscriber::onApiPrepare()` first reads every parameter
through its getter, and only then asks whether it was required. The read
fatals before the check that would have said `Parameter "x" is required.`.

The form that works is core's own dominant one, and the rule's message points
at it: an untyped property, no default, a `@var` docblock for the type, and
`@required`. `?string` and `mixed` do not help. Any typed property without a
default starts uninitialized, so `protected ?string $x;` fatals the same way
unless the constructor assigns it. Properties from traits count too.

The reverse combination is reported as `ck.api4.requiredActionParamWithDefault`.
That is `@required` next to a default the kernel accepts, meaning anything but
`null`, `''`, `[]` or `FALSE`. It promises a validation that can never fire.

The template enables `checkUninitializedProperties`. A
`ReadWritePropertiesExtension` in the same package keeps that setting usable
by declaring action parameters as kernel-written. The rule above is the
precise statement about them.

### Tests must not run DDL inside a transaction

`Civi\Test\TransactionalInterface` wraps each test in a transaction, and MySQL
commits implicitly on any DDL. The transaction ends when `setUp()` or a test
method does any of these:

- creates or saves a `CustomField` or `CustomGroup`
- installs or enables an extension
- runs a literal CREATE, ALTER, DROP, TRUNCATE or RENAME

The rows then stay behind for the next run to trip over. You cannot see this
flake in a single run. The rules `ck.test.customFieldInTransaction`,
`ck.test.extensionInTransaction` and `ck.test.ddlInTransaction` report it.
They follow `$this->…()` helpers, because that is where the schema work
usually sits. The fix is `setUpHeadless()`, never a suppression. The rules only
see this when the test code is analysed at all, which the next section
covers.

### Analysing the test suite

Analysing test code is opt-in, and the switch is a file. The main
`phpstan.neon.dist` covers production code only. Test code is where the
fleet's remaining level-10 debt sits, and a fleet-wide flip would have turned
green repos red.

`scaffold/template/extension/phpstan-tests.neon.dist` is a second, seeded
config. It includes the first one, overrides `paths` to `tests/phpunit` and
keeps level 10. A lower level for test code gives a test suite that will not
catch what it was written for. The honest lever is to not adopt the file yet.

When the file exists, CI runs `phpstan analyse -c phpstan-tests.neon.dist` as
its own gate. When it does not, CI logs a skip line. There is no input and no
second flag. Adopt the file per repo once the run is clean. Measure first,
and fix the test code rather than committing the file with a baseline, since
a baseline here would restore exactly the blind spot the file removes. Until
a repo adopts it, the test-only rules above are inert, which is the strongest
reason to adopt it. ckinit seeds the file into new extensions. In an
existing extension it treats a missing file as neither drift nor something
`--update` fixes.

### Database queries

A catch around a query must not be a silent fallback. If the `try` reaches
the database directly, through `CRM_Core_DAO::executeQuery()`,
`singleValueQuery()` or a DAO's `find()`, the catch has to rethrow or call
`\Civi::log()->error()`. An empty catch, or one that only logs at `debug` or
`info`, turns a failed query into a substituted value the caller cannot tell
from real data. The rule is `ck.db.silentCatch`. APIv4 calls are out of
scope, because their exceptions are legitimate control flow.

A table name in raw SQL is checked against the schema. `DELETE FROM
civicrm_civirules_rule` parses, analyses and unit-tests clean, and then fatals
on a site, because the table is called `civirule_rule`. The rule
`ck.sql.unknownTable` reads the literal SQL of these calls and reports a table
the pinned core does not have:

- `CRM_Core_DAO::executeQuery()`, `singleValueQuery()` and
  `executeUnbufferedQuery()`
- a DAO's `query()`
- `from()` and `join()` on the `CRM_Utils_SQL_Select` builder

The calibration keeps the rule usable. Only names with the `civicrm_` prefix
are judged, because that prefix is core's. An extension table like
`civirule_rule` or `mailjet_event` is invisible to the analysis. These count
as known:

- custom-value and temp tables (`civicrm_value_*`, `civicrm_tmp_*`)
- the tables declared in the repo's own `schema/`, `xml/schema/` or `sql/`
- anything listed under `parameters.civikitchen.sqlKnownTables` in
  `phpstan.neon`

The template seeds that stanza empty. `phpstan.neon.dist` is a seeded file, so
an existing repo adds the key itself on the day it needs one. Non-literal SQL
is skipped silently: a `{$table}` interpolation, a `%1` placeholder in table
position, or a statement built in a variable. That is why other extensions'
`civicrm_`-prefixed tables are the one thing to declare rather than baseline.

### Routes that change data on GET

A route a browser can GET must not change data. Prefetchers, link scanners,
mail-security proxies and the back button all issue GETs nobody typed. An
`<img src="…/civicrm/x/delete?id=5">` on a hostile page fires with the
victim's session. A write on GET is a CSRF hole and a data-integrity bug at
once. The rule is `ck.route.mutationOnGet`.

It checks two kinds of handler: the static `page_callback` methods a repo
declares in `xml/Menu/*.xml`, and `run()` of a `CRM_Core_Page` subclass. It
follows writes two hops through the class's own methods. A write is any of:

- an APIv4 create, save, update, delete or replace, including custom actions
  built on them, like `createMailing()`
- a `civicrm_api3` write
- a DAO or BAO `save()`, `delete()` or `create()`
- a literal INSERT, UPDATE or DELETE in a DAO call

A handler that looks at the request method stays silent. That is
`$request->getMethod() !== 'POST'` with a 405, or a check of
`$_SERVER['REQUEST_METHOD']`, and it is the shape every webhook should have.

No configuration is involved. The rule parses `xml/Menu/*.xml` out of the
analysed repo itself, so a route added there is covered the moment it exists.
A generated `.ck-routes.json` is honoured when present, as an override for a
repo whose routes are not where the parser looks. Generate it with
`php /opt/civikitchen/toolbelt/phpstan/tools/gen-route-catalog.php .`. It is
never a precondition, because a gate that silently stops seeing new routes is
worse than no gate.

One legitimate case is left: a GET that writes on purpose. An example is a
redirect flow whose links are static hrefs in a menu or a SearchKit display,
so no qfKey is available, and which is mitigated by its own refusal of
`Sec-Fetch-Site: cross-site`. That case gets a narrow
`@phpstan-ignore ck.route.mutationOnGet` naming the mitigation, never a
baseline entry.

### Conventions

Use `E::ts()`, never bare `ts()`. The rule is `civikitchen/use-extension-ts`.

Use the standard mixins for managed entities, menus, settings and Angular
instead of bespoke hooks. The rule is
`civikitchen/use-mixins-for-standard-hooks`.

Configuration is shipped as managed entities in `managed/*.mgd.php` or
`.mgd.php` files, not as imperative install-time code.

A managed `Job` on an entity that this repo ships only under `Civi/Api4/` sets
`version=4` in its `parameters`. The job runner parses parameters the APIv3
way, and without it the job fails on every cron pass. The ckconform check
is `managed-job`.

Code is phpstan level 10 clean, with the template's `phpstan.neon.dist`.
Files are at most 1000 lines (`civikitchen/max-file-length`).

Every file has `declare(strict_types = 1)`. Without it, nothing enforces the
types phpstan verifies at runtime. mago's `strict-types` rule checks it. The
baseline arms it everywhere except `tests/phpunit/bootstrap.php`, and
`cklint --fix` inserts the line. The spacing inside it is the one from
`Drupal.WhiteSpace.OperatorSpacing`, and ckfmt writes it
(`space-around-assignment-in-declare`).

### One PHP floor

The PHP floor is stated once and checked from three sides. `require.php` in
`composer.json` is the source of truth. `<php_compatibility>` in `info.xml`
and phpstan's `phpVersion` must agree with it, which the ckconform check
`php-version-coherence` verifies. Without `phpVersion`, phpstan analyses with
the image's PHP. Syntax that only PHP 8.3 has then passes CI and fatals on
the 8.1 site the extension promised to install on.

ckcompat adds what phpstan does not know, using mago against that floor:

- `mago lint --semantics` parses the code as the floor version. PHP 8.4 syntax
  such as `new Foo()->bar()` or property hooks is then a hard error on an 8.3
  floor. The image ships a single PHP 8.4, which makes `php -l` structurally
  blind to exactly that.
- `mago analyze` reports the functions, classes and methods the floor lacks.
  `Dom\HTMLDocument`, for example, arrived in 8.4.
- Two CiviKitchen rules cover what mago has no data for: `C::{$name}` below
  8.3, and `"${var}"` from 8.2.

Functions that CiviCRM core polyfills, such as `array_find()` and
`json_validate()`, count as available, since core ships symfony's polyfills.
So does a newer function, class or method in a file that guards it with
`function_exists()`, `class_exists()` or `method_exists()`. Deprecations come
from mago's Deprecation rules and from the built-in symbols PHP deprecates,
both up to the newest PHP. A function new in a PHP release that mago has no
data for yet goes unreported until a mago bump brings it. ckmodernize reads
the same floor, so rector never rewrites past it.

### `@ck-legacy` for code that must touch a deprecated API

phpstan's deprecation rules ship in the images. They report every call into an
`@deprecated` symbol and exempt only scopes that are deprecated themselves.
That exemption is the wrong tool for the test of a deprecated method, or for
the fixture that feeds a shim. Those are living code, and `@deprecated` on
them would tell callers to stop using them.

Mark such a class, trait or single method with `@ck-legacy` instead.
CiviKitchen ships a `DeprecatedScopeResolver` in `toolbelt/phpstan/` that
treats such a scope as deprecated, so nothing inside it is reported. Use the
exact tag, put it as narrowly as possible, and delete it together with the
shim or test it annotates.

Code that is removed together with the deprecated path, private helpers
included, gets `@deprecated` itself. That is the case the exemption is for.
`@ck-legacy` is a scope marker, not a blanket suppression. Production code
that calls a deprecated API still has to migrate.

### Call-site arguments

Do not pad calls with positional arguments. Arguments that only repeat a
parameter default are dropped, and the arguments after them are named.
ckmodernize rewrites
`retrieve('delete', 'String', NULL, FALSE, NULL, 'POST')` to
`retrieve('delete', 'String', method: 'POST')`.

A bare `TRUE` or `FALSE` at a call site is reported by
`civikitchen/name-boolean-arguments`. Only a human knows which parameter it
is, so the baseline leaves the rule off. A repo arms it once its call sites
are named.

## Tooling every repo must have

### cklint and the mago baseline

cklint runs `php -l` on changed files and `mago lint` with the bundled
baseline. The baseline covers bug patterns, such as loose `==`, empty catch
blocks, `@`, complexity ceilings, aliased functions and unused imports. It
also requires declared visibility on every method and property, and braces on
every control structure. Every finding fails the gate.

The rule set ships in the bundled `mago.toml`. It is mago's defaults minus
everything another gate already owns (ckfmt, Rector, cktaint, spaze), and
minus the CiviCRM house idiom of `isset()` and `empty()` on API arrays. There
is no phpcs config any more, and ckconform fails on a leftover `phpcs.xml`
or `phpcs.xml.dist`. A deliberate single deviation is a
`// @mago-expect lint:<rule>` line in the code, visible in the diff. With the
baseline, mago lints at the floor in `require.php` of `composer.json`. A
committed `mago.toml` replaces the baseline unless it starts with
`extends = ["/opt/civikitchen/toolbelt/mago/mago.toml"]`.

The baseline also runs CiviKitchen's own rules. They are a mago extension in
`toolbelt/mago-ext`, with codes of the form `civikitchen/<rule>`:

- `use-extension-ts`
- `use-mixins-for-standard-hooks`
- `max-file-length`
- `no-unsafe-unserialize`
- `no-tautological-assertion`
- `no-generic-var-on-action-param`
- `no-required-on-external-action`
- `curl-ssl-verify`
- `remote-address`, outside `tests/`
- `case-fall-through`
- `name-boolean-arguments`, off by default

A repo configures them through worker options on the extension host command,
in a `mago.toml` that extends the baseline:

```toml
extends = ["/opt/civikitchen/toolbelt/mago/mago.toml"]

[extension-hosts.civikitchen]
command = ["php", "/opt/civikitchen/toolbelt/mago-ext/worker.php",
  "--exclude=civikitchen/max-file-length:tests/**",
  "--external-actions=Civi\\Api4\\Action\\Widget\\Intake"]
```

The options are:

- `--disable=<code>,…` turns rules off.
- `--exclude=<code>:<glob>,…` scopes them. Globs match the repo-relative path.
- `--max-lines=<n>` moves the file-length ceiling.
- `--ignore-calls=<name>,…` replaces the list of calls that
  `name-boolean-arguments` leaves alone.
- `--external-actions` arms `no-required-on-external-action`. `@required` is
  forbidden on those APIv4 actions, because there an empty parameter has to be
  answered with a verdict, not rejected with an exception.

Repeating the table replaces the baseline's command. Leaving out its
`--disable` therefore arms `name-boolean-arguments`.

### Tests and phpstan config

Each repo has a `phpunit.xml.dist` and headless tests as in the template
(`scaffold/template/extension/`), including the `TEST_DB_DSN` bootstrap
guard.

Each repo has a `phpstan.neon.dist` at level 10, with no baseline,
`phpVersion` at the declared floor, and the `includes:` line for the CiviCRM
ban list.

### Architecture rules

Architecture rules belong in phpstan, not in review. phpat runs inside the
normal `phpstan analyse`, and the fleet-wide rules ship in the image, in
`civikitchen/phpstan-ck-legacy` as `ArchitectureTest`. They are:

- The extension boundary. An extension may depend on core, as listed in the
  generated `CoreNamespaceCatalog`, on itself, and on every extension its
  `info.xml` declares in `<requires><ext>`. Every other `CRM_` or `Civi\`
  symbol is another extension's internals, and the supported way across is
  APIv4.
- The legacy-UI-base ban.
- The APIv4 contract rules above.

All of them derive the extension from its own `info.xml` and classloader
layout, so no repo configures anything. A repo can still add its own
`phpat.test`-tagged classes for repo-specific boundaries.

A declared requirement counts as own code for the boundary rule
(`phpat.testOnlyCoreAndOwnCivicrmDependencies`) only when its checkout is
found. The rule looks in three places:

- a sibling directory `../<key>`
- the CI sibling mount `.civikitchen-siblings/<key>` (see `sibling_repo`)
- the image's ext dir, `CK_EXT_DIR`, which defaults to `/var/www/html/ext`

Each checkout is identified by the `key` in its `info.xml`, not by the
directory name. Core-shipped extensions such as `org.civicrm.search_kit` or
`civi_mail` need no checkout. A required extension that is not found is still
reported, and the message names it. The fix is to mount it, not to add an
`ignoreErrors` entry. `ignoreErrors` is for couplings to extensions the repo
deliberately does not require.

### ckdeps

ckdeps checks `composer.json` against what the code really uses. It reports
shadow, unused and dev-in-prod dependencies. Extensions that depend on core
alone pass silently. CiviCRM is not a composer dependency, and the bundled
config teaches the analyser exactly that, down to the Guzzle, PSR and Symfony
packages core ships. An extension that decorates the container types against
those packages and must not require a second copy. A repo that ships its own
`composer-dependency-analyser.php` replaces the bundled config rather than
extending it, so it has to restate those patterns.

### cksmarty

cksmarty compiles every `.tpl` in the repo with the real `CRM_Core_Smarty`,
in the booted CI stack. It also compiles the bodies of the managed
MessageTemplates the extension installed. It is a step in the normal `ci`
job, not an opt-in, because the boot is the expensive part and that job has
already paid for it.

The static template checks in ckconform say in their headers what they
cannot answer, and this is it. Whether a template compiles depends on the
Smarty major core ships, on the prefilters `CRM_Core_Smarty` installs, and on
which `{crm*}` plugins are registered, your own included. The
message-template half has no other coverage anywhere. Those bodies are Smarty
strings in the database, and otherwise the first thing to compile them is the
workflow firing on a live site. A repo with no `.tpl` passes with a log line.

A managed MessageTemplate that the extension renders itself, without Smarty,
is declared by its managed name with a reason, and cksmarty skips it:

```yaml
policy:
  smarty_skip_templates:
    - template: example_receipt
      reason: Rendered by strtr() over {placeholder} tokens, not by Smarty
```

### ckeslint

ckeslint lints JS and TS with a toolchain pinned in the image. See
[Frontend](#frontend-js-dependencies-js-tests-and-browser-tests). It is also
an unconditional step in `ci`, and the many extensions that ship no JS pass
with a log line.

### ckfmt

ckfmt formats the repo, with [mago](https://mago.carthage.software/) for PHP
and [oxfmt](https://oxc.rs/) for JS and TS. `ckfmt --check` is a hard gate in
`ci`. The bundled mago baseline owns layout, with `preset = "drupal"` plus one
setting for declare spacing, and the linter carries no layout rule. Formatter
and lint gate agree by construction. A red check means "run `ckfmt` and
commit", never a style debate.

Vendored trees, minified bundles and civix- or DAO-generated PHP are excluded.
civix regenerates those files verbatim, and formatting them would put every
`civix upgrade` at war with the gate. A committed `mago.toml` or `.oxfmtrc.*`
wins over the baseline for its half.

ckfmt and ckeslint take their file list from the git index, so a new file
is invisible to both until you `git add` it. cklint also reads untracked
files.

### Rule packs come from the image

Rule packs come from the image, not from each repo's `composer.json`. The mago
baseline and its CiviKitchen rules ship under `toolbelt/mago*`. The phpstan
extensions (deprecation rules, disallowed-calls, strict-rules) are installed
once from the image's committed lockfile in `toolbelt/phpstan-root`. An
extension carries no dev dependencies at all. Third-party rules are
cherry-picked, never a whole foreign standard. A pack that would turn the
fleet red at once, such as `phpstan-strict-rules`, is installed but left out
of the auto-registration, so a repo opts in with one `includes:` line when it
is ready.

### CI and releases

CI follows `scaffold/template/extension/.github/workflows/ci.yml`. That file
is a thin caller of the reusable `extension-ci.yml` in civikitchen, so the
pipeline is defined once instead of copied into every repo. Its in-container
gates are `ck ci`, and `ck ci --help` lists them. A developer runs the same
command unchanged in the local stack.
[Running the CI gates locally](extension-development.md#running-the-ci-gates-locally)
names what runs on the runner instead. A stack whose image predates `ck ci`
fails the gate step with an error saying so. The caller pins `@v1` and the CI
stack pins the matching `:v1` image ([releases.md](releases.md)).

Releases go through the shared `extension-release.yml`
([Releasing an extension](extension-releases.md)). The ckconform check
`release-workflow` fails when no workflow calls it. The only opt-out is
`policy.release.mode: none` with a reason.

### Versions and tags

The `<version>` in `info.xml` is `X.Y.Z` or `X.Y.Z-<pre-release>`. That is
SemVer 2.0 without build metadata and without leading zeros. The ckconform
check `version-format` fails on anything else, such as `2.2.7.1`, `1.0` or
`1.3.0+build.5`. The release workflow accepts no other tag, so such a version
can never be released.

Every version the repo has moved past carries its `v<version>` tag, checked by
`release-tags`. A re-scoped number goes under `policy.untagged_versions`, see
[Releasing an extension](extension-releases.md#a-version-that-will-never-be-released).
The current version is left to `release-tag-coherence`, so the window between
the bump commit and the tag push is not reported twice. On a shallow clone
the rule reports itself as unevaluated rather than clean. The shared CI checks
out with `fetch-depth: 0` and `fetch-tags: true` for exactly this reason.

The `<version>` in `info.xml` sits exactly at the tags or just ahead of them.
`release-tag-coherence` fails in two cases. The first is a version ahead of
the newest tag, where the bump was committed but the tag never cut. The
second is a version below the highest `v*` tag reachable from `HEAD`. Examples
are a repo tagged `v1.0.0` whose `info.xml` went back to `0.1.0`, or a stray
`v1.0.0-alpha1` above `0.1.0-alpha3`. A release from there would sort under
what is already published, so the fix is to release a version above that
tag. Ordering follows SemVer 2.0 precedence
(`1.0.0-alpha.2` < `1.0.0-alpha.10` < `1.0.0`). A tag that is not SemVer is
ignored.

### Committed files

Each repo has a `composer.json` with the extension metadata. Do not commit
`node_modules`, `vendor` or build artifacts. Frontend builds commit only
`dist/`, or declare the uncommitted build under `policy.dist.build` so the
release zip carries it.

`.gitignore` covers every artifact the repo can regenerate: the phpunit result
cache, `vendor/`, `node_modules/` and `*.tsbuildinfo`. ckconform demands only
the entries for what the repo can actually produce. Nagging a PHP-only
extension about `node_modules` is how a checker teaches people to stop reading
it. This is prevention, not detection. phpunit writes its cache next to the
config on every run, so a `git add -A` right after a test run would commit it.

Lockfiles are committed. Every tracked `package.json` needs its
`package-lock.json`, `bun.lock` or equivalent, and a `composer.json` with real
dependencies needs its `composer.lock`. Never put one in `.gitignore`. Without
it nobody can reproduce the build that shipped, and a red CI run cannot be
told apart from a moved dependency. CI installs with the frozen form
(`npm ci`). This is the exact counterpart of the rule above: ignore what a
build regenerates, and commit what pins it.

`<requires>` in `info.xml` names every extension actually used, such as
SearchKit, Afform or CiviRules. A missing `<ext>` only surfaces on a fresh
site.

### Dev stack and workflow permissions

The dev stack is `.docker/docker-compose.yml` on a civikitchen image. Every
image in it is pinned. A bare `image: mariadb` is `:latest` spelled shorter.
Floating tags in a workflow make a run unattributable, and floating tags in
the stack CI boots stop the run from happening at all. On 2026-07-06
`maildev:latest` moved to a release candidate whose healthcheck queries a
route its own app answers with 404. Six stacks stopped coming up, with no diff
to point at. The project's own image is the one exception. It is referenced
through `${CIVIKITCHEN_IMAGE:-…}` and is meant to track its tag.

Every workflow declares a `permissions:` block. Without one, the job token
inherits the repository default, which on older repos and orgs is write-all.
A lint job does not need to be able to push. Set permissions per job where a
step genuinely writes, such as `packages: write` to push an image.

### CI on GitLab

A repository hosted on GitLab declares `ci: gitlab` under `policy:` in
`civikitchen.yaml`. ckinit then writes a managed `.gitlab-ci.yml` instead of
the GitHub callers and `renovate.json`. The file includes civikitchen's
`ci/gitlab/extension-ci.yml` at `v1`:

```yaml
include:
  - remote: https://raw.githubusercontent.com/civico-de/civikitchen/v1/ci/gitlab/extension-ci.yml
```

The jobs run inside the civikitchen image, with the database as a GitLab
service, so a plain docker executor is enough. ckboot provisions the site in
the job container with the checkout attached as the extension. It runs the
repository's `.docker/init.d` hooks and dev profile, as the CI compose stack
does. The gates then run as the web user:

- `civikitchen-ci` runs `ck ci`, then cklifecycle.
- `civikitchen-e2e` runs `npm run test:e2e` when the repository has a
  `playwright.config.ts` or `.js`. The site has the same `admin`/`admin` login
  the GitHub job creates. `CK_IN_IMAGE=1` is set, and the managed
  `tests/e2e/lib.sh` then calls `cv` directly instead of through compose. A
  repository's own helpers check the same variable.

Both jobs run in branch pipelines and in merge request pipelines, so a push to
a branch with an open merge request starts both. A project that wants only
one adds GitLab's `workflow: rules` below the managed block.

Variables below the managed block override the defaults:

| Variable | Default | Effect |
|---|---|---|
| `CK_IMAGE` | `ghcr.io/civico-de/civikitchen:v1` | the job image |
| `CK_DB_IMAGE` | `mariadb:10.11` | keep it equal to the database of `.docker/docker-compose.ci.yml` |
| `CK_LIFECYCLE` | `"1"` | `"0"` skips cklifecycle |
| `CK_NPM_CI` | `"0"` | `"1"` installs `node_modules` before the gates |
| `CK_JS_TESTS` | `"0"` | `"1"` also runs `npm test` |

Dependencies install with `npm ci --ignore-scripts`, as on GitHub. The image
has no Bun. The web user writes into the checkout, which relies on the docker
executor's default umask, so `FF_DISABLE_UMASK_FOR_DOCKER_EXECUTOR` stays off.
GitLab has no shared release pipeline, so such a repository declares
`release: none` with its reason. The mode covers a repository with one
extension at its root, and ckinit refuses it anywhere else.

### Several extensions in one repository

This layout is a repository whose root has no `info.xml` and whose direct
subdirectories are extensions
([layout](extension-development.md#several-extensions-in-one-repository)).
ckconform detects it from the filesystem, and nothing is declared in
`civikitchen.yaml`. The following changes apply in that layout only.

The checks that read workflows are `ci-coverage`, `npm-install`,
`playwright-diagnostics`, `config-without-runner`, and the lint step of
`ci-workflow`. They judge the job that runs this particular extension. That is
the caller job whose `with.working_directory` names its directory, or the
steps job whose `defaults.run.working-directory` does. A neighbour's job says
nothing about this extension. `ci-workflow` therefore fails when no job names
this directory at all. Otherwise five checks would call an extension clean
that nothing builds. `floating-tag` and `workflow-permissions` still judge
the whole file.

`monorepo-requires-mounted` covers a `<requires>` key that is another
extension of the same repository. That extension must be bind-mounted at
`/var/www/html/ext/<key>` into the `app` service of the CI compose file. The
CI compose file is the `compose_file` the scoped job passes to
`extension-ci.yml`, or else its default `.docker/docker-compose.ci.yml`. A
mount in the dev compose file alone does not count, because CI never boots
it. A `compose_file` given as an expression is reported as not evaluated. The
`app` service is the one the site runs in, so a mount into another service
does not count either.

The mount goes under the dependency's key, not its `<file>`. That is where the
template's `phpstanBootstrap.php` resolves a `<requires>` and where the shared
CI mounts a sibling. A dotted key like `de.civico.ckmonobase` is mounted at
`ext/de.civico.ckmonobase`, even though its own stack mounts it as
`ckmonobase`. A mount whose source interpolates a variable (`${VAR}`) is not
guessed, and the rule warns "not evaluated". That volume line is the one
mechanism that works both for a local `docker compose up` and in CI. Without
it the stack boots without the dependency, and the enable fails late. Only the
mount is checked. The entrypoint enables every directory mounted under the
extension directory and resolves each one's `<requires>` first, so the order
of the volume lines does not matter.

`monorepo-version-lockstep` requires every `info.xml` in the repository to
carry the same `<version>` and `<releaseDate>`. One `vX.Y.Z` tag releases all
of them. That keeps `release-tags`, `release-tag-coherence` and the Latest
computation correct, but only while the numbers move together.

The release checks read the extension's own directory. A commit that touches
only a neighbour is not an unreleased change to this extension.

### Third-party source a repo carries verbatim

An extension sometimes ships someone else's code inside its own tree. An
example is a vendored upstream service in `.docker/`, kept byte-identical to
the version that runs in production. Every ck* file list already skips the
conventional `vendor/` and `node_modules/` directories. A path like
`.docker/civiproxy/proxy/` is not skipped, and linting it leaves two bad
options: a permanently red gate, or a formatter run that destroys the property
that made the copy worth having.

Declare such a path instead, with a reason, in `civikitchen.yaml`:

```yaml
policy:
  vendored_paths:
    - path: .docker/civiproxy/proxy
      reason: Unmodified SYSTOPIA CiviProxy 1.0.0-beta
```

cklint, ckfmt and ckeslint then skip it. The key is repeatable, with one
path per entry. Each prefix is anchored at the repo root, so it names one
directory, not every directory that happens to share its name. It is not an
escape hatch for the repo's own code. phpstan keeps its own `paths:`, and
everything you actually wrote stays in scope.

### Formatter and linter

ckfmt owns layout and cklint carries no layout rule, so formatted code is
always lint-clean and the two never undo each other.
`tests/images/test-dev-tools.sh` asserts this on the overlong-line constructs
where the formatter makes its largest decisions: wrapped `extends` and
`implements` lists, long `echo` concatenations and nested array literals.
Each fixture is written unformatted, ckfmt formats it, and cklint must
accept the result.

Where a suppression sits matters for the mago engine. A class-level
`@mago-expect` goes either above the docblock, separated from it by a blank
line, or inside it as a ` * @mago-expect lint:<rule>` line. Never put it as a
`//` line between the docblock and the declaration. There the formatter and
the pragma scanner disagree about what the comment is attached to. On a
method, that `//` line between docblock and declaration is the form in use
across the fleet.

Two more habits help with the mago engine.

Turning a rule off retires its suppressions. mago reports a leftover
`@mago-expect` for a disabled rule as `unfulfilled-expect`, and the baseline's
`minimum-fail-level` makes that a red gate. Remove the expect lines in the
same change.

Run `phpstan` once after `cklint --fix`. The safe fixes include an
inline-variable-return rewrite that can take a `@var` annotation with it, and
the type it pinned is then gone.

## Known vulnerabilities and secrets

There are two scanners on two layers, and neither replaces the other. The
extension CI workflow scans what a repo declares. The image pipeline scans
what a repo runs on. A CVE in the Debian base of the CI image appears in no
lockfile, and a vulnerable npm dependency appears in no image. A pipeline with
only one of the two is blind in the direction it is not looking.

### Dependencies: `osv-scanner` in `extension-ci.yml`

The dependency scan runs as its own job, alongside the template-drift check.
It boots no CiviCRM stack, so it does not queue behind one, and a fresh
advisory does not hide a red PHPUnit run. It reads the committed lockfiles in
the extension directory: `composer.lock`, `package-lock.json` and `bun.lock`.
The extension directory is `working_directory`, which is the repository root
in a one-extension repo. It reads them one at a time and by name, for these
reasons.

It reads lockfiles, not manifests. The range in `composer.json` says what
could be installed. The lock says what CI resolved and what every install
actually got. This is what the committed-lockfile rule above is for.

It reads that directory only, with each file named explicitly. A recursive
scan would also pick up the lockfiles inside `vendor/` and `node_modules/` as
soon as a previous step has materialized them, and a dependency's own lockfile
is not what this repo ships.

A repo with none of the three files passes and says so in the log. A PHP-only
extension with no real composer dependencies is the normal case. An opt-in
check that quietly tests nothing is the failure mode this whole pipeline
argues against.

There is no input to turn the scan off. An advisory published overnight can
turn the fleet red without anyone pushing a commit. That is uncomfortable, and
it is also the point. The escape hatch is per finding, not per repo.

That escape hatch is an `osv-scanner.toml` in the extension directory. The
scanner discovers it on its own because it sits beside the lockfiles, so there
is no flag and no workflow input. It excuses a single advisory or a whole
package, and it makes you write down why:

```toml
[[IgnoredVulns]]
id = "GHSA-xxxx-xxxx-xxxx"
ignoreUntil = 2026-12-31T00:00:00Z
reason = "only reachable through the CLI entry point, which this extension never calls"

[[PackageOverrides]]
name = "vendor/package"
version = "1.2.3"
ecosystem = "Packagist"
ignore = true
reason = "transitive dev-only dependency of the test harness"
```

The tool enforces `ignoreUntil`. Past that date the finding is back, and the
excuse has to be renewed or dropped. The reason is printed on every run. An
entry that no longer matches anything is reported as an unused ignore, so the
file cleans itself up visibly instead of accumulating.

### Repository secrets: `trivy` in `extension-ci.yml`

A separate mandatory job scans the checked-out extension source with Trivy's
secret scanner. Tests and fixtures stay in scope. A credential committed as
"test data" is still in the repository and still needs rotation. The
generated or materialized dependency trees (`vendor/`, `node_modules/`) and
the workflow's CiviKitchen helper checkout are excluded. They are separate
upstream source boundaries and often carry deliberately fake-key fixtures.

A false positive is scoped in `.trivyignore.yaml` in the extension directory,
by rule and path, with a statement and an enforced expiry date. No workflow
switch turns the scanner off for a whole repository.

```yaml
secrets:
  - id: generic-api-key
    paths:
      - tests/fixtures/example-token.txt
    statement: Synthetic non-routable fixture generated from the public example.
    expired_at: 2026-11-01
```

This scans the current checkout, not deleted blobs elsewhere in Git history.
It also reports after the push has already reached GitHub. A one-time
history-wide scan and GitHub Push Protection remain necessary complements.

### Images: `trivy` in `build-dev-images.yml`

The image scan runs after the candidate images are pushed. It scans the
published digest resolved from the registry, not a tag and not the
Dockerfile, because only the digest is the artifact someone actually pulls.
`imagetools create` copies a manifest list intact, so the digest scanned is
bit-for-bit the digest the promote jobs publish.

The gate is `--ignore-unfixed --severity HIGH,CRITICAL --exit-code 1`, limited
to `--scanners vuln`. Unfixed findings do not block, because no rebuild here
can clear them, and a gate that cannot be met only teaches people to ignore
it. A second run, unfiltered and non-blocking, writes the full picture into
the job summary, with every severity and unfixed findings included. That is
what you want when triaging a finding that has started to matter.

The scan reports, but it does not gate the release, and the promote jobs do
not wait for it. A base-image CVE is fixed by an upstream rebuild. Holding the
image back would freeze everyone on an older image with strictly more CVEs.
On the daily cron the scan is wired into the failure notification. A finding
on an unchanged image therefore still opens an issue instead of sitting in a
summary nobody opens.

Central exceptions go in `trivyignore.yaml` in the civikitchen repository
root, passed to the scan with `--ignorefile`. Trivy auto-loads only the plain
`.trivyignore`, and the YAML format is still experimental upstream. The format
carries what the discipline needs. Trivy itself enforces `expired_at`: an
expired entry is pruned, the finding comes back, and the gate goes red.
`paths` scopes an entry to the tree the vulnerable copy actually lives in, so
excusing upstream's copy of a CVE does not also excuse ours.

```yaml
vulnerabilities:
  - id: CVE-0000-00000
    paths:
      - "home/buildkit/buildkit/**"
    statement: <what makes this finding not apply to these images>
    expired_at: 2026-11-01
```

The file is not empty. It excuses upstream trees that the images install and
do not own: CiviCRM core's bundled frontend assets, the civicrm-buildkit
clone's own `node_modules`, Joomla's vendor tree, and a Drupal test fixture
that declares packages whose code is not in the image. It deliberately does
not excuse anything this repo can move. The npm that NodeSource's nodejs
package ships is upgraded in `install-dev-tools.sh` (`NPM_VERSION`) rather
than ignored. The `toolbelt/oxlint` and `toolbelt/oxfmt` lockfiles are
expected to stay clean on their own.

### Secrets in Git history

In the private extension repos, a deploy key or API token can live forever in
a deleted blob that no `git rm` removes. Run a history-wide secret scan over
each private repo, with `gitleaks detect`, `trufflehog git`, or GitHub's own
secret scanning where the plan includes it. Switch on GitHub Push Protection
organization-wide, so the next one is refused at push time rather than found
later. A rotated credential is cheap. A credential nobody knows is in the
history is not.

## Tests and coverage

Every extension with PHP source needs `tests/phpunit`. A config-only
extension may opt out in `civikitchen.yaml` with `policy.tests` set to
`mode: optional` and a reason, and the reason is mandatory. The opt-out holds
whether or not the repo carries a phpunit config. The civix scaffold ships
one, so ckcoverage treats a declared opt-out as nothing to measure rather
than demanding that the config be deleted.

`phpunit.xml.dist` must declare a `<coverage>` section scoped to real
extension code, excluding the civix shim and DAO boilerplate. Without it,
`--coverage-text` measures nothing while still looking like a passing gate.

### Runtime deprecations

Runtime deprecations are test failures. CiviCRM announces them at runtime via
`CRM_Core_Error::deprecatedWarning()` and `deprecatedFunctionWarning()`, which
end in `trigger_error(..., E_USER_DEPRECATED)`. Two settings turn that into a
red build, and the template has both:

- `convertDeprecationsToExceptions="true"` in `phpunit.xml.dist`
- `error_reporting(E_ALL)` in `tests/phpunit/bootstrap.php`

PHPUnit's error handler ignores anything outside the error mask. The PHP CLI
default hides `E_DEPRECATED`, and `Civi\Test\CiviTestListener` only widens the
mask for its own tests. Without the two settings, a call into a deprecated
code path is green and the notice lands in a PHP log nobody reads. The
ckconform check `deprecation-gate` warns when a repo drops either.

### Transactions and headless builders

A transactional test that lost its transaction is a failure. The phpstan rules
under [Code](#code) catch the DDL they can see in a transactional test, and
ckphpunit catches the rest at runtime. It runs the suite with a listener
that writes a marker inside the transaction and looks for it over a second
connection after the rollback. A marker that survived can only have been
committed, and the fixtures would leak into every later test. The fix it
names is the same: move schema work into `setUpHeadless()`, where the
`CiviEnvBuilder` runs before the transaction opens.

An unapplied headless builder installs nothing. `CiviTestListener` discards
the return value of `setUpHeadless()`, so `return ck_headless();` builds a
`CiviEnvBuilder` that never runs. In a single-extension repo, provisioning has
already enabled the extension, and the suite stays green. Call `->apply()` on
every chain, or later in the method on the variable the builder was assigned
to:

```php
return ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();
```

The ckconform check is `headless-builder-applied`.

### Coverage

CI runs the suite with coverage, through ckcoverage or at minimum
`phpunit --coverage-text`. ckcoverage runs through ckphpunit, so the
transaction canary above comes with it, and nothing in the repo has to
reference it.

ckcoverage fails when the run executed no test at all, and when every test
it listed was skipped. phpunit prints "No tests executed!" or a suite of skips
and exits 0 either way, which is not a passing suite. Only `policy.tests` with
`mode: optional` makes such a run acceptable.

ckcoverage points pcov at the extension root for its run. A `src/`, `lib/`
or `app/` without PHP, which are pcov's own guesses, therefore does not hide
the PHP elsewhere.

ckcoverage reports line coverage and fails below the floor set in
`policy.coverage.minimum` in `civikitchen.yaml`. `minimum: 0` is a floor that
is always met, which is different from an absent key. Adopt it in this order:
measure first, set the floor to what you actually have, then ratchet it up. A
floor nobody measured only teaches people to ignore a red build. A floor must
never be lowered to turn a build green.

### Mutation testing

Coverage is the cheap half of the question. A line can be executed by a test
that asserts nothing about it. ckmutate, built on infection, rewrites the
code under the suite, for example by flipping a comparison or dropping a
return. It reports the mutants no test killed. Each survivor is a covered line
the suite does not actually check.

ckmutate reads `policy.mutation.minimum_msi` from `civikitchen.yaml`, and
optionally `policy.mutation.minimum_covered_msi`. Without that key it is a
no-op that exits 0. Adopt it the same way as coverage: measure, set the floor
to what you have, ratchet.

Switch it on in the scheduled `compat.yml` caller with `mutation: true`, and
never in the push run. Scope it with `policy.mutation.paths` to the domain
logic and the exporters, where a surviving mutant means something. Without
`policy.mutation.paths` it mutates only the lines changed against
`CK_MUTATE_BASE`, which defaults to `origin/main`. That mode is useful on a
branch, but not for a weekly reading. A mutation run costs a suite run per
mutant batch, and its score moves with refactors that changed no behaviour.
As a push gate, that is a slow and flappy build.

## Repository policy: `civikitchen.yaml`

The licence declarations in `info.xml`, `composer.json` and every
`package.json` must agree with each other. Which licence to use is your
policy, not this standard's. Pin the expected values in an optional
`civikitchen.yaml` in the extension root, and ckconform enforces them. The
file lives in your repo, so a private policy never has to be published here.

```yaml
version: 1
policy:
  license: Proprietary
  npm_license: UNLICENSED
  copyright: Example Ltd
  template_custom:
    paths:
      - phpstan.neon.dist
    reason: Deliberate template deviation
  renovate_preset: github>example/renovate
  extension_sources:
    - key: org.example.dep
      version: ^1.2
      url: https://example.org/dep-1.2.0.zip
      sha256: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
      reason: Dependency is not served by the registry
```

`version` uses Composer's version-constraint syntax: `1.2.3`, `^1.2`,
`~1.2.3`, or an explicit bounded range. The `info.xml` version in the
downloaded archive must satisfy it. The SHA-256 pin still identifies the exact
tested bytes.

A dependency in a private repository has no URL a container can fetch. It
names a `release` instead of a `url`, with repository, tag and asset. See
[A dependency only a credential can reach](extension-releases.md#a-dependency-only-a-credential-can-reach).
An entry carries either a `url` or a `release`, never both and never neither.

A repo that targets one CiviCRM minor names it once as `civicrm: "6.17"`.
Quote it, because unquoted YAML reads `6.10` as `6.1`. `extension-ci.yml` and
the release smoke test of `extension-release.yml` then run on
`:standalone-<minor>` instead of the contract image, so CI and release cannot
test different cores. Passing their `image` input as well fails the run. The
minor has to be on the supported list in
[images.md](images.md#tags-and-versions). GitLab CI picks its job image before
the repository is read, so there `CK_IMAGE` still names the image.

Values shared across an organisation's repos can sit once in a file of the
same format, named by `CK_DEFAULT_CONFIG`. A repo's own `civikitchen.yaml`
overrides it per key. In CI, the `policy_defaults` input of `extension-ci.yml`
names the `owner/repo` whose root `civikitchen.yaml` is that file. You can
also set it once for the whole organisation as the `CK_POLICY_DEFAULTS`
Actions variable. On a developer machine, export the variable. ckinit reads it
too, for `renovate_preset`.

SPDX disjunctive licensing, as in `"license": ["MIT", "GPL-2.0"]`, is allowed
in both manifests. It satisfies the policy when the expected licence is one of
the members. It is permitted, but still checked, because an unread array would
be a hole straight through every licence rule.

The `<url desc="Licensing">` that civix scaffolds points at the AGPL text.
Relicensing usually edits `<license>` and leaves the link, and a reader trusts
the link over the tag. ckconform therefore fails when the two disagree. A
closed-source package also wants `"private": true` in `package.json`.
`UNLICENSED` states intent, and `private` is what makes `npm publish` refuse.

## Taint analysis: `cktaint`

cktaint asks one question about the extension: does request input reach a
dangerous sink without being escaped on the way? It runs Psalm, and only as a
taint engine, never as a second phpstan. It ships in the image and needs no
per-repo config. CI runs it as one of the `ck ci` gates.

The gate blocks on the classes where a true positive is an outright
vulnerability: `TaintedSql`, `TaintedShell`, `TaintedInclude`,
`TaintedUnserialize` and `TaintedSSRF`. The noisier classes are
`errorLevel="info"` in the bundled config. Those are file paths, headers,
cookies, callables, eval, LDAP and secrets. They are printed in the report
with `--show-info` and are never part of the exit code. A repo that wants its
own rules ships a `psalm.xml` or `psalm.xml.dist`, and cktaint uses it
instead of the bundled config, as cklint does with `mago.toml`.

Run `cktaint` for the whole extension, or `cktaint CRM/Foo.php` for one file
or directory. `cktaint --baseline` accepts today's findings, so you see only
new ones.

### What it finds

Psalm cannot see CiviCRM core, so CiviKitchen supplies a deliberately small
set of stubs ([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md))
in `/opt/civikitchen/toolbelt/psalm/stubs`. Their signatures are verified
against core.

| role | modelled |
| --- | --- |
| sources | **PSR-7 requests** — everything readable off a route handler's `$request`: `getQueryParams` / `getParsedBody` / `getCookieParams` / `getServerParams` / `getUploadedFiles` / `getAttribute(s)` / `getHeader(s)` / `getHeaderLine` / `getRequestTarget`, the `UriInterface` getters (`getQuery`, `getPath`, `getHost`, …), the body via `StreamInterface::getContents` / `__toString` / `read`, and an upload's `getClientFilename` / `getClientMediaType`. Plus `CRM_Utils_Request::retrieve` / `retrieveValue` / `exportValues` / `retrieveComponent` (Psalm covers `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE` natively) |
| SQL sinks | `CRM_Core_DAO::executeQuery` / `executeUnbufferedQuery` / `singleValueQuery` / `composeQuery` (the `$query` argument only), `CRM_Utils_File::runSqlQuery`, and every raw fragment of `CRM_Utils_SQL_Select` (`from` / `join` / `select` / `where` / `groupBy` / `having` / `orderBy` / `onDuplicate`, the `insertInto`/`replaceInto`/`syncInto` table) |
| path sinks | `CRM_Utils_File::createDir` / `cleanDir` / `copyDir` / `duplicate` / `sourceSQLFile` / `findFiles` / `getFilesByExtension` / `replaceDir` / `createFakeFile` / `resizeImage`, `UploadedFileInterface::moveTo` |
| header sinks | `CRM_Utils_System::redirect` / `setHttpHeader` / `download` (its `$name`/`$mimeType`/`$ext` become Content-Disposition and Content-Type) |
| SSRF sinks | `CRM_Utils_HttpClient::get` / `post` / `fetch` and Guzzle — `Client::request` / `requestAsync` / `get` / `post` / `put` / `patch` / `delete` / `head`, the URI argument only |
| escapes | `CRM_Utils_Type::escape` / `escapeAll`, `CRM_Core_DAO::escapeString` / `escapeStrings` — for **SQL taint only**, so a value escaped for the database is still tainted at a shell or path sink. `CRM_Utils_File::makeFileName` / `makeFilenameWithUnicode` for **paths**, `CRM_Utils_String::purifyHTML` for HTML, and `CRM_Utils_String::munge` for all of them at once (it reduces the value to `[a-zA-Z0-9]` plus a separator) |

Everything PHP itself offers is Psalm's own. That covers `exec`, `system`,
`shell_exec`, `proc_open`, `include`, `eval`, `unserialize`, `header`,
`setcookie`, `file_get_contents`, `fopen` and `curl_setopt`, with
`escapeshellarg` and `htmlspecialchars` as escapes. The stubs above cover only
what Psalm cannot see: CiviCRM core, and the PSR-7 and Guzzle classes that
live in the `vendor/` directory cktaint ignores.

Some modelling decisions are worth knowing, because they decide what you see.

The `$params` array of `executeQuery` is not a sink. That is the safe path,
because `composeQuery` type-validates and escapes every placeholder. Tainting
it would flag exactly the code this standard asks you to write.

`CRM_Utils_Type::validate()` is not an escape. For `String`, `Text`, `Memo`
and `Link` it returns the value unchanged, so taint flows straight through
it. A stub that stayed silent here would launder every injection that goes
through a validated string.

The same holds for `$args` of `CRM_Utils_SQL_Select::where()` and related
methods, and for `param()`. The interpolation array is the safe half of the
builder, and only the expression string is a sink.

`$request->getMethod()` is not a source. It is compared, never concatenated.
Tainting it would flag every `if ($request->getMethod() !== 'POST')` guard
the standard asks you to write.

`CRM_Utils_String::stripPathChars()` is not an escape. It strips quotes and
shell metacharacters but leaves `/`, `.` and `..` alone, so a traversal walks
straight through it. It stays a pass-through in the graph.

Guzzle's `$options` (body, headers, query) is not an SSRF sink. Only the URI
is. An attacker-controlled body sent to a fixed host is not SSRF, and
flagging it would turn every forwarder red.

Hooks, APIv4 parameters and Smarty assignments are deliberately not modelled
as sources. Everything would be tainted, and nobody reads a report where
everything is flagged.

### What it does not see

Psalm follows direct data flow, not the framework. It does not see anything
that leaves PHP and comes back: a value stored via a hook, passed through the
API kernel, or rendered by a Smarty template.

It does not check HTML or XSS. `TaintedHtml` is switched off. CiviCRM's output
escaping happens in templates Psalm never analyses, so what remained would be
Psalm's blind spot, not missing escaping.

It does not see values that leave a function through a by-reference
out-parameter. The case that matters in practice is
`parse_str($request->getUri()->getQuery(), $query)`. As far as Psalm is
concerned, `$query` comes out clean, so a handler that parses its query
string this way is invisible. `json_decode()`, on the other hand, propagates
fine. If you read a webhook body and index into the decoded array, the flow is
still followed.

It does not report additional call sites on a sink it already reported. Psalm
reports one flow per source/sink pair. Fix the first `executeQuery` finding,
and the next one can appear in the following run. A clean cktaint after a
fix is not proof there was only one.

False positives also occur. `CRM_Utils_Request::retrieve($name, 'Positive')`
is safe, because core validates the value. The type arrives as a runtime
string, though, so Psalm taints it like any other retrieval. Expect false
positives of exactly this shape.

### Handling a finding

1. Fix the flow. This is almost always right and almost always small.
   Parameterize the query (`%1` plus `$params`), run the value through
   `CRM_Utils_Type::escape($value, 'String')`, or validate the path.
2. Or record it as a justified exception. Either put
   `@psalm-suppress TaintedSql` on the line with a comment saying why it is
   safe, or run `cktaint --baseline` to write `psalm-baseline.xml`. A baseline
   entry is a dated statement that the finding was read and accepted. A
   baseline nobody wrote a reason for is only a mute button.

Never fix a finding by weakening the stubs. Fixture pairs in
`tests/images/test-dev-tools.sh` hold them in place. For every modelled
source/sink combination there is one file where the flow must be reported,
and one with the escape in between that must stay silent. A weakened stub
therefore shows up as a red image test, not as a quieter report.

## Frontend: JS dependencies, JS tests and browser tests

For an extension with a `package.json`, the default CI run leaves out three
things. It does not install the tree, which is never committed (see the
lockfile rule above). It does not run the JS unit suite. It does not drive the
UI in a browser. Six opt-in inputs on `extension-ci.yml` add these,
all off by default:

| Input | What it adds |
|---|---|
| `npm_ci` | `npm ci --ignore-scripts` on the runner, before the stack boots. The stack bind-mounts the checkout, so `node_modules/` is there for PHP code that reads an asset bundle. |
| `js_tests` | Runs `npm test`. Implies `npm_ci`. Fails when `package.json` has no `test` script. |
| `bun` | Uses Bun for all of the above instead of npm: `bun install --frozen-lockfile`, `bun run test`, `bun run test:e2e`. Implies the install, the way `js_tests` implies `npm_ci`. Needs a committed `bun.lock` and `"packageManager": "bun@x.y.z"` in `package.json`. That is the Bun the jobs install, so a Bun release cannot change a green run on its own. |
| `playwright` | Own job: boots the stack on a free host port with an `admin` / `admin` demo user, then runs `npm run test:e2e` from the runner, on `image`. The test fixtures' browser runs in its own container (the Playwright image of the checkout's `playwright-core` version), so other jobs' Docker networks on the same host cannot abort its requests. Its `localhost` is tunnelled to the runner, so the site's port and any `webServer` the suite starts answer as before. A browser the suite launches itself (`chromium.launch()`) stays on the runner. Report and traces are uploaded on failure. |
| `matrix_playwright` | Runs that job on every `matrix_images` entry as well, one leg and stack per image. Needs `playwright` and `matrix_images`, see below. |
| `node_version` | Node for all of the above. Default `'24'`, the major the dev images ship, so a browser job tests the Node the image actually serves. Still applies under `bun`, see below. |

Playwright retains traces on failure by default, because they are the most
useful diagnosis of a browser-only failure. They can also contain action
parameters, request data, tokens, cookies and authenticated browser state. A
manual live-provider suite that uses reusable credentials should therefore set
`trace: 'off'`, and put a file suppression with a reason in that one config:

```ts
// ckconform-ignore-file playwright-diagnostics -- live-provider credentials must not persist in traces
```

The exception does not cover any other Playwright config in the same
repository. The normal suppression-hygiene check reports missing reasons,
unknown check names, and suppressions whose finding is gone.

### Linting JavaScript

Linting the JS needs none of these inputs. ckeslint runs in the `ci` job on
every push, inside the container, with a toolchain pinned in the image. There
is no Node setup step, no `npm install`, and no linter devDependency in your
`package.json`. The engine is [oxlint](https://oxc.rs). The baseline is
deliberately not a style guide
([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md)). It
targets mistakes, not fashion, and consists of:

- oxlint's `correctness`, `suspicious` and `perf` categories, plus its
  `promise` plugin
- Mozilla's `eslint-plugin-no-unsanitized`, for `innerHTML` and its family,
  since an XSS in an extension is an XSS on every site that installs it
- the type-aware TypeScript rules, only when the repo has a `tsconfig.json`.
  This is where `no-floating-promises` and `no-misused-promises` come from.

The type-aware rules apply to `.ts` and `.tsx` only. Plain `.js` is checked by
the syntactic rules and `no-unsanitized`. The APIv4 contract rule
(`civikitchen/api4-contract`, see [Code](#code)) runs on both. CiviCRM's
globals `CRM`, `cj`, `ts`, `_` and `angular` are declared for you. `dist/`,
`vendor/`, `node_modules/`, the vendored-asset directories and `*.min.js` are
ignored.

Node globals in an e2e suite come from the image. The type-aware rules are
type-aware about `process.env` too. Without `@types/node` in the repo's own
`node_modules`, it is an error type, and every expression touching it trips
`no-unsafe-assignment`, `no-unsafe-member-access` and `no-unsafe-return`.
ckeslint links the image's pinned copy into `node_modules/@types/node` for
the run and removes it afterwards, so no repo needs the devDependency.
tsgolint does not auto-include `@types` the way `tsc` does, though, so your
`tsconfig.json` still has to say so:

```json
{ "compilerOptions": { "types": ["node"] } }
```

A repo that installs its own `@types/node` keeps it. The link is only created
when nothing is there.

TypeScript's compiler diagnostics are opt-in
([ADR-0009](adr/0009-type-checking-javascript-from-outside.md)):

```yaml
policy:
  javascript:
    type_check: true
```

ckeslint then also reports what `tsc --checkJs` would. Examples are a
property that does not exist on the type, a call with the wrong number of
arguments, or JSDoc that contradicts the code.

A repo with a `tsconfig.json` gets these diagnostics through that file, so
what it includes and how strict it is stay the repo's choice. A repo without
one keeps its normal lint run unchanged and gets a second pass that reports
only TypeScript's diagnostics. That pass runs over a copy of the repo's
tracked JS and TS, beside a tsconfig with `allowJs` and `checkJs`, `strict`
off, and CiviCRM's globals declared as `any`. The repo's `node_modules` is
linked in so imports resolve. A path given on the command line that holds no
tracked JS or TS is named as not type-checked.

Plain scripts share one global scope, as scripts on one page do. Two scripts
that never load together but declare the same top-level `const` are therefore
reported as a redeclaration. An IIFE or a module keeps them apart. On older
code, expect findings in bulk. Most of them are JSDoc the code has outgrown,
such as `{object}` where `{Object}` was meant, or a `@returns` that lists half
the fields.

A repo with its own `.oxlintrc.json` sets `"options": {"typeCheck": true}`
there. The policy key would not reach that config, so ckeslint stops with an
error instead of ignoring it.

If you ship your own `.oxlintrc.json`, it wins outright. The baseline is not
merged into it or layered under it. It is not used at all. Owning the config
means owning its `jsPlugins` too. oxlint resolves those against your own
`node_modules`, so a repo with its own config also turns `npm_ci` on.

An `eslint.config.*` on its own is not a config the gate can use. ESLint is no
longer in the image. ckeslint fails with a pointer rather than quietly
linting your code with rules you did not choose. Translate the config to an
`.oxlintrc.json`, or drop it and take the baseline.

### Test scripts

There are two scripts for two suites, and the names are the contract:

- `test` is the JS unit suite, in whatever runner the repo picked, such as
  vitest, bun or `node --test`. The workflow only knows the standard script
  slot, and what it starts is your business. For a pure-function suite this is
  the bulk of the frontend's coverage, so it is the one to have first.
- `test:e2e` is the Playwright suite. It is not `test`, so that enabling
  `js_tests` never accidentally starts a browser run, and so both suites stay
  separately reachable from CI.

`--ignore-scripts` is fixed, not an input. An `install` or `postinstall` hook
is arbitrary code from anywhere in the dependency tree, running against a
writable checkout with a token in the environment. Nothing in an extension
build needs one at that moment. A package that must build before its tests
should do it from its own `test` script, where it is visible in the log.

`npm_ci` and `js_tests` cost seconds and belong in the push run:

```yaml
    with:
      js_tests: true      # implies npm_ci
      # bun: true         # same steps, run with Bun instead of npm
```

### Bun instead of npm

`bun: true` switches the package manager for every frontend step at once: the
install, `js_tests` and the Playwright job. It is deliberately not per-step. A
repo that resolves its dependencies with npm in one job and with Bun in
another is testing two different trees.

`bun.lock` must be committed. The install is `bun install --frozen-lockfile`,
which refuses to run without one. That is the rule the lockfile section
already states. It is also why the npm dependency cache is switched off on
this path: the cache keys on `package-lock.json`, and `setup-node` fails the
step when it cannot find one.

Node is still installed, at `node_version`. Bun replaces npm, not the runtime.
`bun run` starts your scripts, and what those scripts call, such as `tsc`,
`vitest` or `playwright` from `node_modules/.bin`, has a
`#!/usr/bin/env node` shebang. The two inputs therefore do not compete. There
is no Bun equivalent of `node_version`, and the workflow installs the Bun the
setup action ships.

`--ignore-scripts` has no counterpart. Bun runs no dependency lifecycle script
at all unless the dependency is listed as a `trustedDependency`, which is what
the flag buys on the npm path. Bun does run your own package's install
scripts, which `npm ci --ignore-scripts` here does not. Keep the build in the
`test` script either way, and the two paths behave the same.

`npx playwright install` stays `npx` in the browser job. That is not a
package-manager call. It runs the local binary that either manager has just
placed in `node_modules/.bin`.

### The browser job

The browser job does not belong in the push run. It boots a stack, installs
browsers, and is the slowest thing in the pipeline. Put `playwright: true` in
the scheduled `compat.yml` caller below, next to the matrix.

The shared workflow cannot ask about the setup, so the job assumes the
following.

The stack is the standalone CI stack from the template. The job publishes it
only on a port that is free on the runner, and drops any port the compose file
publishes itself. This needs Docker Compose 2.24.4 or later. It sets
`CIVIKITCHEN_SITE_URL` to `http://localhost:<that port>`, overriding the
template's 8080. CiviCRM bakes that URL into every asset and redirect, and
browser jobs on one self-hosted host share its ports, so no fixed port would
do. A CMS compose file boots fine, but its login is the CMS's, so the suite
has to handle that itself. The demo user the job creates is a Standalone
user.

The suite reads `CIVICRM_BASE_URL`, `DEMO_USER` and `DEMO_PASS` from the
environment, as the starter's `playwright.config.ts` and `tests/auth.setup.ts`
already do. The job sets all three, with `CIVICRM_BASE_URL` set to the site
URL above. A hardcoded `localhost:8080` reaches nothing.

All browsers are installed, so a config with `webkit` or `firefox` projects
works without another input. GitHub-hosted runners also use `--with-deps`.
Self-hosted runner images must provide the system libraries themselves,
because CI does not get passwordless `sudo`.

A starter you can copy, including the config files and the `test:e2e` script,
is in
[`examples/extension-with-playwright/`](https://github.com/civico-de/civikitchen/tree/main/examples/extension-with-playwright/).

By default the job runs once, on `image`. UI behaviour that breaks per CiviCRM
version is real but rare, and a browser run per image is the most expensive
thing this pipeline can do. It therefore takes a second opt-in.
`matrix_playwright: true`, next to `playwright: true` and a non-empty
`matrix_images`, adds one leg per `matrix_images` entry. Each leg runs on its
own stack and port, so the legs run side by side. If either of the other two
is missing, `matrix_playwright` fails the run instead of testing nothing. Each
leg is a job named `playwright (<image>)`, or `playwright ()` for the leg on
an unset `image`. The report artifact of every leg after the first carries its
index.

## Private dependencies

civikitchen is public, and the shared workflow needs no token to be called.
Not every extension's dependencies are public, though. There are two cases,
with two opt-in inputs and one optional pair of secrets shared by both. Both
inputs default to off, and a caller that sets neither gets exactly the run it
has today.

| Input / secret | What it adds |
|---|---|
| `composer_install` | `composer install --no-interaction --no-progress` on the runner, before the stack boots. |
| `sibling_repo` | Comma- or newline-separated `owner/repo` list of further extensions, each entry optionally pinned as `owner/repo@ref`. Each one is checked out to `.civikitchen-siblings/<key>` and bind-mounted read-only into the stack, which also enables them, left to right, before this extension. |
| `composer_app_repositories` | Comma- or newline-separated repository names within the caller's owner. Required with the App secrets, and scopes the minted token to exactly these private dependencies. |
| `composer_app_id`, `composer_app_private_key` (secrets) | A GitHub App with `contents: read`, installed on the private dependency repos. The workflow mints a short-lived installation token restricted by `composer_app_repositories` and uses it for both the composer install and the sibling checkout. |

### composer_install

A repo without a committed `vendor/` needs one before the stack boots. The
extension's main `.php` file requires the autoloader at the top, so otherwise
the entrypoint cannot even enable the mounted extension. Before every boot the
workflow runs `ck internal composer-install` as the runner user, in the image,
over the mounted extensions. Public packages need nothing more. Private
packages need `composer_install`, which installs on the runner first, so the
read token never enters a container. The later step then sees `vendor/` and
skips the directory.

CI installs the locked development tree, because static analysis covers tests
and must therefore resolve their framework classes. Release packaging stays
strictly `--no-dev`, so test-only packages never enter the shipped archive.

### sibling_repo

`sibling_repo` is for an extension that implements another extension's
interfaces. The classes must exist at boot, because `cv ext:enable` wants the
declared requirement present. phpstan and the test bootstrap resolve them
from the sibling's ext directory.

The input takes a list, comma- or newline-separated like
`composer_app_repositories`. It enables the siblings left to right before this
extension, so a sibling that another sibling requires has to be listed first.
The mount target is each sibling's extension key, read from its `info.xml`.
It is not the repo name, which is free to differ and is not what CiviCRM
registers the extension under. List the sibling in `<requires>` in
`info.xml` as well. That is what the managed `phpstanBootstrap.php` autoloads,
and what the boundary rule accepts as own code (see
[Tooling](#tooling-every-repo-must-have)).

An entry may pin the checkout as `owner/repo@ref`, where `ref` is a tag, a
branch or a full 40-character commit. Without a pin, the sibling's default
branch is used. A break there then turns every consumer red, and an old run no
longer reproduces. Pin a tag and bump it deliberately.

Because the directory is the key, two entries whose `info.xml` declares the
same key fail the run instead of overwriting each other. So do the keys `ci`
and `policy`, because the workflow checks its own helper repositories out
under those two names.

Each sibling is mounted as is, read-only, and no `composer install` runs in
it. A sibling that keeps its own `vendor/` out of git is not supported yet.
Say so on the issue rather than working around it.

The checkouts land inside your checkout, because the workspace is the only
place a runner checkout may write. They are not treated as your code.
cklint ignores `.civikitchen-siblings/`, and ckconform reads tracked files
only. You do not need to add anything to your `.gitignore`, because the
directory only ever exists on a runner.

`playwright-e2e.yml` takes the same `sibling_repo` input for a single repo.
That workflow never boots the stack itself. It hands the checkout to the
caller's own `prepare_command`, `test_command` and `cleanup_command` through
three environment variables:

| Variable | Value |
|----------|-------|
| `CK_SIBLING_DIR` | Absolute path of the checkout, `$GITHUB_WORKSPACE/.civikitchen-siblings/<key>`, named after the extension key from the sibling's `info.xml`, not its repo name. |
| `CK_SIBLING_KEY` | That extension key on its own, for an enable list or a mount target. |
| `CK_SIBLING_OVERRIDE` | Path of the compose override that bind-mounts the sibling read-only. Layer it with a second `-f` on your `up`. It only mounts. `playwright-e2e.yml` does not know the stack's enable list, so `CIVIKITCHEN_ENABLE_EXTENSIONS` stays yours. |

All three are unset when `sibling_repo` is empty.

### Caller example

A caller with both inputs:

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      composer_install: true
      sibling_repo: myorg/othersibling@v1.4.0
    secrets:
      composer_app_id: ${{ secrets.COMPOSER_APP_ID }}
      composer_app_private_key: ${{ secrets.COMPOSER_APP_PRIVATE_KEY }}
```

With two siblings, list the required one first. The YAML block scalar keeps
one repo per line. A single-line `myorg/base, myorg/othersibling` is the same
input.

```yaml
    with:
      sibling_repo: |
        myorg/base
        myorg/othersibling
```

Name the two secrets you pass, and do not write `secrets: inherit`. Inherit
hands the reusable workflow every secret the repo has, for the sake of two.
The workflow it hands them to is one you move by retagging. Least privilege
costs one line here.

### The GitHub App

The secrets hold a GitHub App's credentials, rather than a PAT or deploy keys:

1. Create one GitHub App in the organisation, under *Settings → Developer
   settings → GitHub Apps*. It has no webhook, no callback, and the repository
   permission Contents: Read-only and nothing else. Generate a private key
   (PEM).
2. Install the app on the organisation and restrict it to the private
   dependency repositories: the composer packages and any sibling. A token
   minted from it reaches exactly those repos and expires within the hour. It
   belongs to no person, so it survives someone leaving. A PAT is the opposite
   on both counts. Per-repo deploy keys do not scale past one dependency,
   because ssh cannot tell which key a given repo wants.
3. Store the App ID as the secret `COMPOSER_APP_ID` and the PEM as
   `COMPOSER_APP_PRIVATE_KEY` in each calling repo. An organisation secret
   scoped to private repos needs a paid GitHub plan. Nothing goes into the
   dependency repos themselves.

The token works over HTTPS only. Declare private Composer packages by HTTPS
URL in `composer.json`, as `"url": "https://github.com/myorg/mypackage"` with
an optional `.git` suffix. Then re-resolve `composer.lock` so its `source.url`
matches. A `git@github.com:` URL cannot use the token and fails with
"repository not found".

### Which jobs use them

Both inputs apply to every job that boots a stack, not only `ci`. The opt-in
extra jobs check the siblings out and install the lockfile the same way. Those
are `matrix_images`, `mutation`, `upgrade_from_last_release`,
`schema_parity`, `core_upgrade_from` and `playwright`. Two jobs move the
working tree between commits: `upgrade_from_last_release` and
`schema_parity`. They re-run the install after each move, so each boot sees
the `vendor/` tree of the commit it is testing.

One limit is worth knowing before you switch anything else on. `composer.lock`
still has to be committed, as the lockfile rule above says. An install that is
not reproducible is a moving target, not a dependency. The install runs on the
runner's PHP, not the image's, and installs the lock as it stands, with no
`update` and no `--ignore-platform-reqs`. Pin `config.platform.php` in
`composer.json` to the PHP the extension really runs on, so resolution and
execution agree.

## Compatibility: test the range you claim

`<compatibility><ver>` in `info.xml` is a promise. The default CI run checks
one CiviCRM version, on one day, installed from scratch. Five opt-in inputs on
the shared `extension-ci.yml` close that gap. All five default to off, and a
caller that sets none of them gets exactly the run it has today.

| Input | What it adds |
|---|---|
| `matrix_images` | One extra job per CiviKitchen image tag (comma-separated, each listed once). It boots the stack and runs the PHPUnit suite against that CiviCRM, plus the lifecycle gate with `lifecycle`. With `matrix_playwright` every tag also gets a browser leg, see [Frontend](#frontend-js-dependencies-js-tests-and-browser-tests). |
| `lifecycle` | After the suite, in the running stack: `disable` → `enable` → `uninstall` → `install`, then asserts the extension is installed again, plus the post-uninstall and log checks described [below](#the-lifecycle-gate-what-cklifecycle-adds-to-the-cycle). |
| `upgrade_from_last_release` | Installs the newest reachable git tag, swaps the working tree to the tested commit, runs `cv upgrade:db --mode=ext` and asserts no upgrade stayed pending. |
| `schema_parity` | Own job: installs the last release and upgrades it to this commit, then installs this commit from scratch, and diffs the two schemas over the extension's own tables. See [below](#schema-parity-does-the-upgrader-arrive-where-install-does). |
| `core_upgrade_from` | Own job: installs the site on an older CiviKitchen image, then moves that same database to the run's image and upgrades it with `cv upgrade:db`. See [below](#core-upgrade-what-an-existing-site-goes-through). |

Pick the ends of your claimed range, not everything in between: the oldest
minor you support, and current stable. The image tags are
`:standalone-<minor>`, for example `:standalone-6.16`, and the moving
`:standalone`. See [images.md](images.md#tags-and-versions). A `<minor>` tag
keeps being rebuilt only while it is on the supported list there, so a minor
that your matrix or `core_upgrade_from` pins has to be on it.
`:standalone-beta` and `:standalone-master` are what core ships next, built
daily from its branches. A red leg there can be a core regression, so they
belong in the scheduled caller, not in the push run.

The matrix jobs run the suite, not the full `ci` pass. cklint, ckconform,
phpstan and the coverage floor are enforced by the tools inside the image.
Re-running them on a pinned older image would grade that image's tooling
rather than your extension. The extra boot answers the version question: does
the extension install here, and do the tests pass here.

### The lifecycle gate: what `cklifecycle` adds to the cycle

The step runs cklifecycle from the image. The `cv ext:*` sequence is only
its setup. The checks around it are the actual gate.

After uninstall, the database must be back where it started. `cv
ext:uninstall` exiting 0 means only that `uninstall()` did not throw. The
check asserts that:

- no table survived that the extension declares (`sql/*.sql`, `xml/schema/`)
  or that carries its `<file>` prefix
- `civicrm_managed` holds no row for the module
- no option group, option value or scheduled job with that prefix is left
  running against code that is gone

A managed record that core keeps by policy, and the entity it points to, are
not leftovers. That is `cleanup` set to `never`, or `unused` while the record
is referenced.

Nothing may fail quietly during the cycle. The gate scans every step's output
plus the delta of the CiviCRM ConfigAndLog files for these:

- `SQLSTATE[…]`
- `DB Error`
- `Table '…' doesn't exist`
- `Unknown table`
- `Unknown column`
- `Duplicate column`
- `Syntax error or access violation`
- PHP Fatal, Parse, Warning and Recoverable lines

Otherwise an `install()` whose `try`/`catch` swallows a missing-table error
leaves the run green and the site broken.

Settings options must load. After the re-enable, the gate calls
`\Civi\Core\SettingsMetadata::getMetadata()` with `loadOptions` on for every
setting the extension declares, and reports each failing setting by name. A
`table` pseudoconstant without `keyColumn` or `labelColumn`, or a `callback`
that does not resolve, throws. A key that core's settings code does not read
only raises a PHP warning, which the gate also counts as a failure. The
ckconform check `settings-metadata` is the static version of this. Either
problem stays invisible until someone opens `/civicrm/admin/theme` or the
extension's own settings page, because neither install nor the test suite
loads options.

`PHP Notice` and `PHP Deprecated` are not matched. Core emits them on every
supported version, and deprecations are already covered by the phpunit
config's `convertDeprecationsToExceptions` and by phpstan. The gate is scoped
to this job and never to the suite, because a negative test that asserts an
API call fails is supposed to log an error.

The findings land in the job summary. A line that is genuinely expected is
declared in `civikitchen.yaml` with a reason, in the same shape ckinit uses:

```yaml
policy:
  lifecycle:
    log_ignore:
      - pattern: "Unknown column 'is_legacy'"
        reason: Dropped in the 5.x upgrader
```

### Schema parity: does the upgrader arrive where install() does?

`upgrade_from_last_release` proves that the upgrader runs and leaves nothing
pending. It says nothing about where the upgrader arrives. `install()` and the
`upgrade_NNNN()` chain are two independent descriptions of the same schema,
and nothing keeps them in step. A column can be `NOT NULL` in one and nullable
in the other, or `varchar(64)` here and `varchar(255)` there. The installer
can create an index the upgrader forgets. Every fresh install is then fine,
every upgraded site is quietly different, and no later upgrader ever
reconciles them, because none of them knows there is anything to reconcile.

Switch it on with `schema_parity: true`.

The job uses two databases in one stack. It installs the last tag, moves the
mount to this commit, runs `cv upgrade:db --mode=ext` and dumps the schema.
Then it runs `down -v`, boots again on this commit for a fresh install, dumps
again, and diffs the two. The diff is scoped to the tables the extension
declares itself, in `schema/*.entityType.php` and `xml/schema/**`. Core's own
tables are not your business and would drown the signal.

The diff is `mysqldump --no-data` plus a normalised text diff, not Atlas. The
CI stack publishes no database port, so any tool has to run inside the app
container. That container already ships a mysql client. Atlas would need a
pinned binary download per run, to diff two whole schemas it has no way to
restrict to your tables. The normalisation is deliberately small and lives in
`SchemaDiffCommand::normalize()` (`toolbelt/lib/php/src/Cli/`). It drops the
`AUTO_INCREMENT` counter, mysqldump's `/*!…*/` version wrappers, trailing
whitespace and blank lines, and nothing else. The trade-off is that this
compares DDL text, so a semantically empty difference is possible. When that
happens, the fix is one more normalisation rule there.

ckschemadiff carries the steps as subcommands (`tables`, `dump`, `diff`,
`normalize`), for reproducing a finding by hand inside the app container.

The job skips itself with a log line when the extension declares no tables of
its own, or when nothing has been released yet. Both are ordinary states for a
healthy extension. That differs from `upgrade_from_last_release`, where a
missing tag means you switched on a check you cannot satisfy.

### Core upgrade: what an existing site goes through

`matrix_images` moves the core but installs from scratch every time.
`upgrade_from_last_release` moves the extension across a core that stays put.
Neither reaches the combination every real site is in: records and
configuration created on core N, and then the core underneath them moves to
N+1. That is where a managed entity reconciles differently after a schema
change, where a saved search's stored API params stop being valid, and where a
custom field's column survives a migration in name only.

Configure it with one image tag, the version to upgrade from, which is the
oldest minor you claim:

```yaml
      core_upgrade_from: ghcr.io/civico-de/civikitchen:standalone-6.16
```

The target is the run's own `image`, or the compose file's default. That reads
the way you think about it: upgrade from the oldest minor I claim to the one I
test on. The two must therefore not be the same version. If they are, the job
says so and fails, rather than passing green over an upgrade that never
happened.

The job does this in one stack:

1. Boots the stack on `core_upgrade_from`, which installs CiviCRM and enables
   your extension on the old version.
2. Runs `tests/upgrade/seed.php` (optional, see below).
3. Recreates the containers on the target image, keeping both the database
   and the site directory.
4. Runs `cv upgrade:db`, then asserts that the database reached the code's
   version, that the version actually moved, that no extension upgrade stayed
   pending, and that your extension is still installed.
5. Runs `tests/upgrade/assert.php` (optional).

The fixtures are yours, and both are optional. Which records prove
persistence is the extension's business. The workflow defines two slots and no
schema, the same deal as the `test:e2e` script name.

- `tests/upgrade/seed.php` runs with `cv scr` on the old core, right after the
  install. Create the records and configuration whose survival matters.
- `tests/upgrade/assert.php` runs with `cv scr` after the upgrade. Any
  exception or non-zero exit fails the job, so an assertion is a `throw` and
  there is nothing to register.

```php
// tests/upgrade/seed.php — on the old core
\Civi\Api4\Contact::create(FALSE)
  ->addValue('contact_type', 'Individual')
  ->addValue('last_name', 'UpgradeFixture')
  ->addValue('my_group.my_field', 'teal')
  ->execute();

// tests/upgrade/assert.php — after the upgrade
$rows = \Civi\Api4\Contact::get(FALSE)
  ->addSelect('my_group.my_field')
  ->addWhere('last_name', '=', 'UpgradeFixture')
  ->execute();
if (($rows->single()['my_group.my_field'] ?? NULL) !== 'teal') {
  throw new RuntimeException('the custom field value did not survive the upgrade');
}
```

These are scripts rather than a PHPUnit class. The headless harness points
`CIVICRM_DSN` at the scratch DB, which the upgrade never touches
([ADR-0012](adr/0012-upgrade-fixtures-are-scripts.md)).

Without the two files, the job still boots the old core, upgrades it and runs
the four assertions above. That is worth having. Its honest limit is that it
says nothing about your own data, and the log says so when it finds no
fixtures.

It is the slowest single check in the pipeline: two boots plus a full core
schema upgrade. It runs once, on `image`, and nothing multiplies it by
`matrix_images`. A multi-hop upgrade matrix would be a separate feature, not a
flag.

### The scheduled caller

`lifecycle` runs inside the push run's stack. The matrix, upgrade,
schema-parity and core-upgrade jobs each cost at least one more full stack
boot, so none of them belongs in the push run. Keep `ci.yml` fast, because it
is what a PR waits on and what automerge gates on. Add a second, thin caller
for the slow checks, with one file and one schedule:

```yaml
# .github/workflows/compat.yml
name: Compatibility
on:
  schedule:
    - cron: '0 5 * * 1'   # Mondays, after that day's image rebuild
  workflow_dispatch:      # and on demand, before a release

permissions:
  contents: read

jobs:
  compat:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      # This caller gets the default `ci` job too — there is one reusable
      # workflow, not two. Point it at the moving edge instead of repeating
      # the pinned :v1 run every push already does: together with the oldest
      # minor below, the weekly run then covers both ends of the range.
      image: ghcr.io/civico-de/civikitchen:standalone
      # Oldest minor from info.xml <compatibility>.
      matrix_images: ghcr.io/civico-de/civikitchen:standalone-6.16
      lifecycle: true
      upgrade_from_last_release: true
      # The other half of that question: the upgrader ran, but did it arrive
      # where install() does? Skips itself when the extension owns no tables.
      schema_parity: true
      # The site an existing user has: installed on that oldest minor, then
      # upgraded to the image above. Two boots and a core schema upgrade —
      # the slowest check here, and the one nothing else covers.
      core_upgrade_from: ghcr.io/civico-de/civikitchen:standalone-6.16
      # Browser tests, for a repo that has a test:e2e script — another stack
      # boot, which is exactly why it lives here and not in ci.yml.
      # playwright: true
      # The browser suite on the matrix image too, for UI that changes between
      # CiviCRM versions: one more boot per matrix image.
      # matrix_playwright: true
      # Mutation testing (ckmutate): does the suite assert on what it covers.
      # A no-op until civikitchen.yaml sets policy.mutation.minimum_msi — and set
      # policy.mutation.paths too: without it a run on main mutates only the
      # lines changed against origin/main, which is nothing.
      mutation: true
      # The drift job already runs on every push in ci.yml.
      check_template: false
```

A scheduled workflow only runs from the default branch. A red weekly run
therefore means the released state is broken, which is worth an issue, not a
rerun. `workflow_dispatch` is there for the moment that matters most: before
cutting a release.

The weekly run and a push run on the same branch leave each other alone.
Every concurrency group in `extension-ci.yml` carries the calling workflow's
`name:` and the `image`, so a job is cancelled only by its own copy in a newer
run of the same caller. That holds as long as the two callers' names differ.
Keep the second caller's `name:` distinct from the template's `CI`.

`compat.yml` is not managed by the template. Which versions you claim is your
policy, so ckinit neither stamps nor checks this file.
