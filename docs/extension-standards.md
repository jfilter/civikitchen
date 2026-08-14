# Extension standards: what a modern extension looks like

The checklist the civikitchen tooling (cklint / CiviKitchen phpcs standard,
ckmodernize, phpstan, the extension template) enforces or expects. Use it for
audits and as the target state when modernizing an existing extension.

For a new extension, run `/path/to/civikitchen/scaffold/ckcreate <key>` to
generate the civix scaffold and apply the versioned
`scaffold/template/extension/` tooling layer in one atomic operation. For an
existing civix extension, `ckinit.php <extension-directory>` applies that layer;
existing files remain untouched unless `--force` is explicitly supplied. Afterwards,
`ckinit.php --check` reports where template-managed files have drifted and
`ckinit.php --update` refreshes them (seeded files like `composer.json` and
`phpstan.neon.dist` stay the repo's own after the first copy) — see
[extension-development.md](extension-development.md#civix-workflow). Use the
`ckinit.php` from the civikitchen checkout at the version the repo pins;
[releases.md](releases.md) explains what a version covers.

## UI: declarative before imperative

- **Listings/reports** → SearchKit `SavedSearch` + `SearchDisplay` (managed),
  not `CRM_Core_Page`/`CRM_Report_Form` + Smarty. Custom reports are
  deprecated in core.
- **Forms** → Afform/FormBuilder (`ang/*.aff.html`), custom handling via
  `hook_civicrm_afformSubmit` or APIv4 actions — not QuickForm
  (`CRM_Core_Form`).
- **Data endpoints** → APIv4 actions, not page callbacks.
- Enforced by the phpat rule `testNoLegacyUiBases` (in
  `civikitchen/phpstan-ck-legacy`, auto-registered in every phpstan run).
  It resolves the full ancestry, so an own intermediate base or a concrete
  core report subclass is caught too — unlike the retired
  `CiviKitchen.Legacy.NoLegacyPageForm` sniff, which only saw the direct
  `extends`. Audits should still grep for `.tpl` templates and page routes.
- Legitimate exceptions (raw callback endpoints, iframe hosts, third-party
  framework bases like CiviRules' `CRM_CivirulesActions_Form_Form` that
  mandate QuickForm) and grandfathered screens get an `ignoreErrors` entry
  for `phpat.testNoLegacyUiBases` in `phpstan.neon.dist`, with a reason.
- Afform: `permission` in `*.aff.json` is declared `data_type => Array` in core
  (`Civi\Api4\Afform`), and all 36 afforms core ships use the list form
  `["access foo"]`. Prefer it. A plain string is tolerated but non-canonical —
  core silently `explode(',')`s it, so a permission name containing a comma
  would be split into two.

## Code

- APIv4 only (`civicrm_api4()` / OO builders) — no `civicrm_api3()`. Enforced
  by phpstan, not phpcs: the bans live in
  `/opt/civikitchen-phpstan-config/civicrm-disallowed.neon`
  (spaze/phpstan-disallowed-calls) and the template's `phpstan.neon.dist`
  includes it. Because phpstan resolves types, an indirect `$class::value()`
  is caught too. An exception is an `allowIn`/`allowInMethods` entry in the
  repo's own `phpstan.neon.dist`, with the reason next to it. phpstan reads PHP
  only, so `ckconform` additionally rejects `CRM.api3` in JS/Smarty; annotate a
  genuine exception there with `ck-allow-api3 -- <reason>`.
- **An APIv4 entity has to exist in the core you claim to support.** Entities
  resolve at runtime, so `\Civi\Api4\Foo` compiles, passes phpstan and passes
  every test that never loads that page — then fatals in production. Check the
  entity's `@since` against `<compatibility><ver>`, and remember core ships
  entities from bundled extensions (`ext/civi_mail` …), which then belong in
  `<requires>`. `ckconform` verifies each referenced entity exists in the core
  it runs against. An entity supplied by a required third-party extension is
  declared narrowly as
  `known_api4_entities=ExternalEntity -- supplied by required example.ext` in
  `.ckconform`; unused entries and declarations without a provider fail.
- **The strings inside an APIv4 call are a contract, and phpstan now reads
  it.** Entity, action and field names are literals nothing checks until the
  call runs — a typo in a select survives the whole pipeline and then returns
  the wrong data on a customer's site. The rules in
  `civikitchen/phpstan-ck-legacy` check `civicrm_api4()` and the fluent form
  against `Api4Catalog`, generated from the pinned core:
  `ck.api4.unknownEntity` (only when the name is a near-miss of a real
  entity — another extension's entities are not in the catalog and must not
  be flagged), `ck.api4.unknownAction`, `ck.api4.unknownField` in `select`,
  `where`, `orderBy`, `groupBy` and `values`. Actions arrive through class
  inheritance *and* through traits (`Generic\Traits\ManagedEntity` gives
  some twenty entities `export()`/`revert()`), and the catalog follows both.
  Everything the source tree cannot settle is skipped in silence: non-literal
  names, dotted names (joins, `custom.*`, price-set fields), SQL expressions
  and their aliases, `segment_*` from SearchKit, camelCase BAO pass-through
  params in a write, and every entity whose field list is not table-backed
  (Setting, Afform, ECK, `SK_*`, `Custom_*`). A finding is therefore worth
  reading rather than baselining. A builder held in a variable
  (`$q = Contact::get(); $q->addSelect(…)`) is judged through its type, which
  names the entity exactly; only `orderBy`/`groupBy` are skipped there,
  because an alias defined by an earlier link is out of sight.
- **The right-hand side of an implicit join is checked too.** The catalog
  carries a join map (`Api4Catalog::JOINS`): every field with an
  `entity_reference` becomes a joinable of the same name
  (`SchemaMapBuilder::addJoins`), plus the eight primary/billing links
  `ContactSchemaMapSubscriber` adds to Contact. So in
  `addSelect('address_primary.street_address')` the left side is resolved to
  Address and the right side checked against Address's fields — the correct
  form of the address-fields-on-Contact bug now gets the same scrutiny as the
  wrong one, `address_primary.no_such_field` included. It stays silent
  wherever the source tree cannot decide: a left side the map does not know
  (an explicit `->addJoin('Address AS x')` alias, a custom-group name, another
  extension's entity), a name an explicit join rebound, a multi-level path
  (`a.b.c`), a builder held in a variable — there the joins an earlier link
  declared are invisible — and any target entity whose own field list is not
  table-backed. Writes (`addValue`/`setValues`) are out of scope: a join is
  not a write target.
- **A protected property on a custom APIv4 action is an API parameter.** The
  kernel fills it from the caller's params, so a non-nullable typed property
  without a default does not produce an API validation error when the caller
  omits it — it produces PHP's "must not be accessed before initialization",
  from inside the kernel, naming no field
  (`ck.api4.uninitializedActionParam`). **`@required` does not rescue that
  form**, which is the counter-intuitive part and was verified in a running
  install: `ValidateFieldsSubscriber::onApiPrepare()` reads every parameter
  through its getter *first* and only then asks whether it was required, so
  the read fatals before the check that would have said
  `Parameter "x" is required.`. The form that works is core's own dominant
  one — **untyped property, no default, `@var` docblock for the type,
  `@required`** — and it is what the rule's message points at. The reverse combination, `@required`
  next to a default or a nullable type, promises a validation that can never
  fire (`ck.api4.requiredActionParamWithDefault`). A third, quieter reading —
  `protected ?string $x;`, nullable with no default, which PHP leaves
  uninitialized rather than null until the kernel writes it — is usually what
  the author meant, so it ships **off**: set
  `parameters.civikitchen.strictActionParams: true` in the repo's
  `phpstan.neon.dist` to turn on `ck.api4.nullableActionParamWithoutDefault`.
  The recommendation is `= null`, written out; the separate identifier exists
  so a repo can adopt or ignore this one without touching the other two. The
  template enables
  `checkUninitializedProperties`; a `ReadWritePropertiesExtension` in the same
  package keeps that usable by declaring action parameters
  kernel-written — the rule above is the precise statement about them.
- **DDL inside a transactional test is the flake you cannot see.**
  `Civi\Test\TransactionalInterface` wraps each test in a transaction, and
  MySQL commits implicitly on any DDL — so `CustomField`/`CustomGroup`
  create/save, an extension install/enable, or a literal
  CREATE/ALTER/DROP/TRUNCATE/RENAME in `setUp()` or a test method ends that
  transaction, and the rows stay behind for the next run to trip over
  (`ck.test.customFieldInTransaction`, `ck.test.extensionInTransaction`,
  `ck.test.ddlInTransaction`). The rule follows `$this->…()` helpers, because
  that is where the schema work usually sits. The fix is `setUpHeadless()`,
  never a suppression — and the rule only sees this when the test code is
  analysed at all, which is the next bullet.
- **Analysing the test suite is opt-in, and the switch is a file.** The main
  `phpstan.neon.dist` covers production code only, because test code is where
  the fleet's remaining level-10 debt sits and a fleet-wide flip would have
  turned green repos red. `scaffold/template/extension/phpstan-tests.neon.dist` is a
  second, seeded config that includes the first one, overrides `paths` to
  `tests/phpunit` and keeps **level 10** — a lower level for test code is a
  test suite that will not catch what it was written for; the honest lever is
  not adopting the file yet. CI runs `phpstan analyse -c
  phpstan-tests.neon.dist` as its own gate **when the file exists** and logs a
  skip line when it does not; there is no input and no second flag. Adopt it
  per repo once the run is clean — measure first, and fix the test code rather
  than commit the file with a baseline, since a baseline here would restore
  exactly the blind spot the file removes. Until a repo adopts it the
  test-only rules above are inert, which is the strongest reason to. `ckinit`
  seeds it into new extensions and treats its absence in an existing one as
  neither drift nor something `--update` fixes.
- **A catch around a query must not be a silent fallback.** If the `try`
  reaches the database directly (`CRM_Core_DAO::executeQuery()`,
  `singleValueQuery()`, a DAO's `find()`) the catch has to rethrow or
  `\Civi::log()->error()`; an empty catch, or one that only logs `debug`/
  `info`, turns a failed query into a substituted value the caller cannot
  tell from real data (`ck.db.silentCatch`). APIv4 calls are out of scope:
  their exceptions are legitimate control flow.
- **A table name in raw SQL is checked against the schema.** `DELETE FROM
  civicrm_civirules_rule` parses, analyses and unit-tests clean, and then
  fatals on a site — the table is called `civirule_rule`. The rule reads the
  literal SQL of `CRM_Core_DAO::executeQuery()`/`singleValueQuery()`/
  `executeUnbufferedQuery()`, a DAO's `query()`, and the
  `CRM_Utils_SQL_Select` builder's `from()`/`join()`, and reports a table the
  pinned core does not have (`ck.sql.unknownTable`). The calibration is what
  keeps it usable: only `civicrm_`-prefixed names are judged at all, because
  that prefix is core's — an extension table like `civirule_rule` or
  `mailjet_event` is invisible to the analysis and stays silent. Custom-value
  and temp tables (`civicrm_value_*`, `civicrm_tmp_*`), the tables the repo's
  own `schema/`, `xml/schema/` or `sql/` declares, and anything listed under
  `parameters.civikitchen.sqlKnownTables` in `phpstan.neon` count as known
  (the template seeds that stanza empty; `phpstan.neon.dist` is a seeded
  file, so an existing repo adds the key itself the day it needs one).
  Non-literal SQL — a `{$table}` interpolation, a `%1` placeholder in table
  position, a statement built in a variable — is skipped without a word.
  That last list is why other extensions' `civicrm_`-prefixed tables are the
  one thing to declare rather than baseline.
- **A route a browser can GET must not change data** (`ck.route.mutationOnGet`).
  Prefetchers, link scanners, mail-security proxies and the back button all
  issue GETs nobody typed, and an `<img src="…/civicrm/x/delete?id=5">` on a
  hostile page fires with the victim's session — a write on GET is a CSRF
  hole and a data-integrity bug at once. The rule checks two kinds of
  handler: the static `page_callback` methods a repo declares in
  `xml/Menu/*.xml`, and `run()` of a `CRM_Core_Page` subclass. Writes are
  followed two hops through the class's own methods (APIv4 create/save/
  update/delete/replace including custom actions built on them like
  `createMailing()`, `civicrm_api3` writes, DAO/BAO `save()`/`delete()`/
  `create()`, and literal INSERT/UPDATE/DELETE in a DAO call). A handler
  that looks at the request method — `$request->getMethod() !== 'POST'` with
  a 405, or `$_SERVER['REQUEST_METHOD']` — is silent, which is the shape
  every webhook should have. No configuration is involved: the rule parses
  `xml/Menu/*.xml` out of the analysed repo itself, so a route added there
  is covered the moment it exists. (A generated `.ck-routes.json` —
  `php /opt/civikitchen-phpstan-ext/tools/gen-route-catalog.php .` — is
  honoured when present, as an override for a repo whose routes are not
  where the parser looks; it is never a precondition, because a gate that
  silently stops seeing new routes is worse than no gate.) The one
  legitimate case left is a
  GET that writes on purpose — a redirect flow whose links are static hrefs
  in a menu or a SearchKit display, so no qfKey is available, mitigated by
  its own `Sec-Fetch-Site: cross-site` refusal. That is a narrow
  `@phpstan-ignore ck.route.mutationOnGet` naming the mitigation, never a
  baseline entry.
- `E::ts()`, never bare `ts()` (`CiviKitchen.I18n.UseExtensionTs`).
- Standard mixins for managed entities / menu / settings / Angular — no
  bespoke hooks (`CiviKitchen.Extension.UseMixinsForStandardHooks`).
- Config as managed entities (`managed/*.mgd.php` or `.mgd.php`), not
  install-time imperative code.
- phpstan level 10 clean (template `phpstan.neon.dist`), files ≤ 1000 lines
  (`CiviKitchen.Files.MaxFileLength`).
- `declare(strict_types = 1)` in every file
  (`SlevomatCodingStandard.TypeHints.DeclareStrictTypes`; `cklint --fix`
  inserts it). Without it the types phpstan verifies are enforced by nothing at
  runtime. The spacing is not cosmetic: `Drupal.WhiteSpace.OperatorSpacing`
  wants the spaces and ckfmt writes them (`space-around-assignment-in-declare`),
  so the CiviKitchen standard sets Slevomat's `spacesCountAroundEqualsSign` to
  1. With a 0 there the two sniffs demand opposite things and no file in the
  fleet can satisfy both.
- **One PHP floor, stated once and checked from three sides.**
  `composer.json` `require.php` is the source of truth; `info.xml`
  `<php_compatibility>` and phpstan's `phpVersion` must agree with it
  (`ckconform` `php-version-coherence`). Without `phpVersion`, phpstan analyses
  with the image's PHP — 8.3-only syntax then passes CI and fatals on the 8.1
  site the extension promised to install on. `ckcompat` adds what phpstan does
  not know, in two stages against that floor: `mago lint --semantics` parses
  the code AS the floor version (so PHP-8.4-only syntax such as
  `new Foo()->bar()` or property hooks is a hard error on an 8.3 floor — the
  image ships a single PHP 8.4, which makes `php -l` structurally blind to
  exactly that), then PHPCompatibility for the version-specific APIs a parse
  cannot see (`json_validate()` arrived in 8.3). `ckmodernize` reads the same
  floor, so rector never rewrites past it.
- **`@ck-legacy`, not `@deprecated`, for code that must touch a deprecated
  API.** phpstan's deprecation rules (bundled in the images) report every call
  into an `@deprecated` symbol and exempt only scopes that are themselves
  deprecated. That exemption is the wrong tool for the test of a deprecated
  method or the fixture feeding a shim: they are living code, and
  `@deprecated` on them would tell callers to stop using them. Mark the class,
  trait or the single method with `@ck-legacy` instead — CiviKitchen ships a
  `DeprecatedScopeResolver` (`toolbelt/phpstan/`) that treats
  such a scope as deprecated, so nothing inside it is reported. Exact tag; put
  it as narrow as possible, and delete it together with the shim or test it
  annotates. It is a scope marker, not a blanket suppression — production code
  calling a deprecated API still has to migrate.
- No positional padding: arguments that only repeat a parameter default are
  dropped and what follows them is named — `ckmodernize` rewrites
  `retrieve('delete', 'String', NULL, FALSE, NULL, 'POST')` to
  `retrieve('delete', 'String', method: 'POST')`. Bare `TRUE`/`FALSE` at a call
  site is a warning (`CiviKitchen.Modern.NameBooleanArguments`) — only a human
  knows which parameter it is.

## Tooling every repo must have

- `phpcs.xml.dist` referencing `<rule ref="CiviKitchen"/>` (project layer on
  top is yours) — `cklint` picks it up automatically.
- Warnings vs errors in the phpcs layer is a real distinction, not decoration:
  a sniff is a warning where the fix needs human judgement (which parameter is
  that `TRUE`? is this permission bypass the legitimate one?). `cklint` prints
  them and exits 0 on them — only phpcs *errors* and mago findings fail the
  gate. Ship warnings down over time; don't let them block a release.
- `cklint` also runs `mago lint` as a second engine: bug-pattern rules the
  phpcs standard does not carry (loose `==`, empty catch blocks, `@`,
  complexity ceilings). The rule set ships in the bundled `mago.toml` —
  subtractive, mago's defaults minus everything another gate already owns
  (phpcs/Slevomat/Rector/cktaint/spaze) and minus the CiviCRM house idiom
  (`isset()`/`empty()` on API arrays). A deliberate single deviation is a
  `// @mago-expect lint:<rule>` line in the code, visible in the diff; a
  committed `mago.toml` replaces the baseline outright.
- `phpunit.xml.dist` + headless tests per the template
  (`scaffold/template/extension/`), incl. the `TEST_DB_DSN` bootstrap guard.
- `phpstan.neon.dist` (level 10, no baseline, `phpVersion` at the declared
  floor, and the `includes:` line for the CiviCRM ban list).
- **Architecture rules belong in phpstan, not in review.** `phpat` runs inside
  the normal `phpstan analyse`, and the fleet-wide rules ship in the image
  (`civikitchen/phpstan-ck-legacy`, `ArchitectureTest`): the extension
  boundary — an extension may depend on core (per the generated
  `CoreNamespaceCatalog`) and on itself; every other `CRM_`/`Civi\` symbol is
  another extension's internals, and the supported way across is APIv4 —
  plus the legacy-UI-base ban and the APIv4 contract rules above. All derive
  the extension from its own
  `info.xml`/classloader layout, so no repo configures anything. A repo can
  still add its own `phpat.test`-tagged classes for repo-specific boundaries.
- `ckdeps` checks `composer.json` against what the code really uses (shadow /
  unused / dev-in-prod dependencies). Extensions depending on core alone pass
  silently — CiviCRM is not a composer dependency, and the bundled config
  teaches the analyser exactly that.
- `cksmarty` compiles every `.tpl` in the repo — and the bodies of the managed
  MessageTemplates the extension installed — with the real `CRM_Core_Smarty`,
  in the booted CI stack. It is a step in the normal `ci` job, not an opt-in:
  the boot is the expensive part and that job has already paid for it. The
  static template checks in `ckconform` say in their own headers what they
  cannot answer, and this is it — whether a template compiles depends on the
  Smarty major core ships, on the prefilters `CRM_Core_Smarty` installs and on
  which `{crm*}` plugins are registered, your own included. The message-template
  half is the part with no other coverage anywhere: those bodies are Smarty
  strings in the database, and the first thing that compiles them is the
  workflow firing on a live site. No `.tpl` in the repo is a pass with a log
  line.
- `ckeslint` lints JS/TS with a toolchain pinned in the image — see
  [Frontend](#frontend-js-dependencies-js-tests-and-browser-tests). Also an
  unconditional step in `ci`, and also a pass-with-a-log-line for the many
  extensions that ship no JS.
- `ckfmt` formats the repo — [mago](https://mago.carthage.software/) for PHP,
  [oxfmt](https://oxc.rs/) for JS/TS — and `ckfmt --check` is a hard gate in
  `ci`. The bundled mago baseline (`preset = "drupal"` plus one declare-spacing
  setting) is tuned so its output is clean under the bundled phpcs standard:
  formatter and lint gate agree by construction, so a red check means "run
  `ckfmt` and commit", never a style debate. Vendored trees, minified bundles
  and civix/DAO-generated PHP are excluded — civix regenerates those files
  verbatim, and formatting them would put every `civix upgrade` at war with
  the gate. A committed `mago.toml` or `.oxfmtrc.*` wins over the baseline for
  its half.
- **Rule packs come from the image, not from each repo's `composer.json`.**
  phpcs standards (civicrm/coder, PHPCompatibility, Slevomat) and phpstan
  extensions (deprecation rules, disallowed-calls, strict-rules) are installed
  and pinned once in `toolbelt/install-dev-tools.sh` — an extension carries
  no dev dependencies at all. Third-party rules are cherry-picked, never a
  whole foreign standard, and every pack that would turn the fleet red at once
  (`phpstan-strict-rules`) is installed but left out of the auto-registration
  so a repo opts in with one `includes:` line when it is ready.
- CI per `scaffold/template/extension/.github/workflows/ci.yml` — a thin caller of the
  reusable `extension-ci.yml` in civikitchen (compose stack → cklint +
  ckconform → ckfmt --check → phpunit under ckcoverage → phpstan → phpstan over
  the tests when the repo opted in → ckcompat →
  ckdeps → cktaint → cksmarty → ckeslint → template-drift check →
  lockfile vulnerability scan, plus the opt-in schema-parity job), so
  the pipeline is defined once instead of copy-pasted per repo. The caller pins
  the released major (`@v1`) and the CI stack the matching `:v1` image —
  workflow, template, tools and images are one versioned contract, so they move
  together and deliberately ([releases.md](releases.md)). One canary repo
  tracks `@main` and declares that in its `.ckconform`.
- Releases through the shared `extension-release.yml` — a tag push builds the
  installable zip (dev/CI files excluded), installs it into a fresh CiviCRM and
  publishes the GitHub release. The version lives in `info.xml` and
  `composer.json` and they are bumped together; `ckrelease check` is what says
  so out loud. See [Releasing an extension](extension-releases.md). Not a
  template-managed file yet, so adoption is per repo and one line.
- `composer.json` with the extension metadata; no `node_modules`/`vendor`/build
  artifacts committed (frontend builds commit only `dist/`).
- `.gitignore` covers every artifact the repo can regenerate — the phpunit
  result cache, `vendor/`, `node_modules/`, `*.tsbuildinfo`. `ckconform` demands
  only what the repo can actually produce, and only those: nagging a PHP-only
  extension about `node_modules` is how a checker teaches people to stop reading
  it. Prevention, not detection — phpunit writes its cache next to the config on
  every run, so a `git add -A` right after a test run commits it.
- **Lockfiles are committed** — every tracked `package.json` needs its
  `package-lock.json`/`bun.lock`/…, and a `composer.json` with real
  dependencies needs its `composer.lock`. Never `.gitignore` one: without it
  nobody can reproduce the build that shipped, and a red CI run cannot be told
  from a moved dependency. CI installs with the frozen form (`npm ci`). This is
  the exact counterpart of the rule above: ignore what a build regenerates,
  commit what pins it.
- `info.xml` `<requires>` naming every extension actually used (SearchKit,
  Afform, CiviRules …) — a missing `<ext>` only surfaces on a fresh site.
- Dev stack: `.docker/docker-compose.yml` on a civikitchen image. **Every image
  in it is pinned** — a bare `image: mariadb` is `:latest` spelled shorter.
  Floating tags in a workflow make a run unattributable; floating tags in the
  stack CI boots stop the run happening at all. On 2026-07-06 `maildev:latest`
  moved to a release candidate whose healthcheck queries a route its own app
  answers with 404, and six stacks stopped coming up with no diff to point at.
  The project's own image is the one exception: it is referenced through
  `${CIVIKITCHEN_IMAGE:-…}` and is meant to track its tag.
- Every workflow declares a `permissions:` block. Without one the job token
  inherits the repository default, which on older repos and orgs is write-all —
  a lint job does not need to be able to push. Set it per job where a step
  genuinely writes (`packages: write` to push an image).

### Third-party source a repo carries verbatim

An extension sometimes ships someone else's code inside its own tree — a
vendored upstream service in `.docker/`, say, kept byte-identical to the version
that runs in production. The conventional `vendor/` and `node_modules/`
directories are skipped by every ck* file list already; a path like
`.docker/civiproxy/proxy/` is not, and linting it leaves exactly two bad
options: a permanently red gate, or a formatter run that destroys the property
that made the copy worth having.

Declare it instead, with a reason, in `.ckconform`:

```ini
vendored_paths=.docker/civiproxy/proxy -- unmodified SYSTOPIA CiviProxy 1.0.0-beta, must stay byte-identical to production
```

`cklint` (both engines), `ckfmt` and `ckeslint` then skip it. The key is
repeatable, one path per line, and each prefix is anchored at the repo root — it
names one directory, not every directory that happens to share its name. It is
not an escape hatch for the repo's own code: `phpstan` keeps its own `paths:`,
and everything you actually wrote stays in scope.

### Known formatter/phpcs stand-offs

`ckfmt` and `cklint` are tuned to agree, and where they cannot the ruleset
yields: the formatter owns layout. Anything else would leave a repo with no
green state at all — `phpcbf` reindents, the next `ckfmt` puts it back, and the
two gates oscillate forever. The sniffs that yield, each with the construct
that forced it:

- **Wrapped `extends` / `implements` lists** — `Drupal.Classes.ClassDeclaration`
  (`InterfaceWrongIndent`, `SpaceBeforeName`, `ImplementsLine`).
- **`echo` with a long concatenation chain** —
  `Squiz.WhiteSpace.LanguageConstructSpacing.IncorrectSingle` and
  `Squiz.WhiteSpace.SemicolonSpacing.Incorrect`.
- **A nested array literal opening as `[[`, or a key whose value is an array** —
  `Squiz.Arrays.ArrayDeclaration` (`CloseBraceNewLine`, `IndexNoNewline`).
- **An array value the formatter wraps across lines** —
  `Drupal.Arrays.Array.ArrayIndentation` for the value itself, and
  `Drupal.WhiteSpace.ScopeIndent.IncorrectExact` for the sibling keys that
  follow it.
- **The `function_exists('cv')` block in the test bootstrap** —
  `Squiz.WhiteSpace.FunctionSpacing`, scoped to that one file.

Indentation therefore has exactly one owner: `ckfmt` rewrites a wrong indent on
the same file set the standard covers, so nothing goes unchecked.

Every one of these is asserted in `tests/images/test-dev-tools.sh`: the fixture
is written unformatted, `ckfmt` formats it, and `phpcs` must accept the result.
A new stand-off ships with its fixture, or the next release re-opens it.

Suppression placement matters for the mago engine. A class-level
`@mago-expect` goes either **above the docblock**, separated from it by a blank
line, or **inside** it as a ` * @mago-expect lint:<rule>` line — never as a
`//` line between the docblock and the declaration, where the formatter and the
pragma scanner disagree about what the comment is attached to.

Two more habits around the mago engine:

- Turning a rule off retires its suppressions. mago reports a leftover
  `@mago-expect` for a disabled rule as `unfulfilled-expect`, and the baseline's
  `minimum-fail-level` makes that a red gate — remove the expect lines in the
  same change.
- Run `phpstan` once after `cklint --fix`. The safe fixes include an
  inline-variable-return rewrite that can take a `@var` annotation with it, and
  the type it pinned is then gone.

## Known vulnerabilities: dependencies and images

Two scanners, two layers, and neither replaces the other. The extension CI
workflow scans what a repo *declares*; the image pipeline scans what a repo
*runs on*. A CVE in the Debian base of the CI image appears in no lockfile
anywhere, and a vulnerable npm dependency appears in no image — so a pipeline
with only one of the two is blind in the direction it isn't looking.

**Dependencies — `osv-scanner` in `extension-ci.yml`.** Its own job, alongside
the template-drift check: it boots no CiviCRM stack, so it doesn't queue behind
one, and a fresh advisory doesn't hide a red PHPUnit. It reads the *committed
lockfiles in the repository root* — `composer.lock`, `package-lock.json`,
`bun.lock` — one at a time and by name:

- Lockfiles, not manifests. The range in `composer.json` says what *could* be
  installed; the lock says what CI resolved and what every install actually
  got. This is what the committed-lockfile rule above is for.
- Root only, named explicitly. A recursive scan would also pick up the
  lockfiles inside `vendor/` and `node_modules/` as soon as a previous step has
  materialized them, and a dependency's own lockfile is not what this repo
  ships.
- A repo with none of the three — a PHP-only extension with no real composer
  dependencies is the normal case — passes and **says so in the log**. An
  opt-in check that quietly tests nothing is the failure mode this whole
  pipeline argues against.

There is no input to turn it off. An advisory published overnight can turn the
fleet red without anyone pushing a commit, which is uncomfortable and also the
point; the escape hatch is per finding, not per repo.

That escape hatch is an **`osv-scanner.toml` in the repository root**, which the
scanner discovers on its own because it sits beside the lockfiles — no flag, no
workflow input. It excuses a single advisory or a whole package, and it makes
you write down why:

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

`ignoreUntil` is enforced by the tool, not by convention: past that date the
finding is back and the excuse has to be renewed or dropped. The reason is
printed on every run, and an entry that no longer matches anything is reported
as an unused ignore — so the file cleans itself up out loud instead of
accumulating.

**Images — `trivy` in `build-dev-images.yml`.** Runs after the candidate images
are pushed, against the **published digest** resolved from the registry, not
against a tag and not against the Dockerfile: only the digest is the artifact
someone actually pulls. `imagetools create` copies a manifest list intact, so
the digest scanned is bit-for-bit the digest the promote jobs publish.

The gate is `--ignore-unfixed --severity HIGH,CRITICAL --exit-code 1`, limited
to `--scanners vuln`. Unfixed findings don't block because no rebuild here can
clear them, and a gate that cannot be met only teaches people to ignore it. A
second run, unfiltered and non-blocking, writes the full picture — every
severity, unfixed included — into the job summary, which is what you want when
triaging a finding that has started to matter.

It reports rather than gates the release: the promote jobs don't wait for it. A
base-image CVE is fixed by an upstream rebuild, and holding the image back would
just freeze everyone on an older one with strictly more of them. On the weekly
cron the scan is wired into the failure notification, so a finding on an
unchanged image still opens an issue instead of sitting in a summary nobody
opens.

Central exceptions go in **`trivyignore.yaml` in the civikitchen repository
root**, passed to the scan with `--ignorefile` (trivy auto-loads only the plain
`.trivyignore`; the YAML format is still experimental upstream). The format
carries what the discipline needs: `expired_at` is enforced by trivy itself —
an expired entry is pruned, the finding comes back and the gate goes red — and
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

The file is not empty. What is excused there is upstream trees the images
install and do not own — CiviCRM core's bundled frontend assets, the
civicrm-buildkit clone's own `node_modules`, Joomla's vendor tree, and a Drupal
test fixture that declares packages whose code is not in the image. What is
deliberately *not* excused is anything this repo can move: the npm that
NodeSource's nodejs package ships is upgraded in `install-dev-tools.sh`
(`NPM_VERSION`) rather than ignored, and the `toolbelt/oxlint` and
`toolbelt/oxfmt` lockfiles are expected to stay clean on their own.

**Recommended, not implemented: secrets.** Neither scanner looks for
credentials in git history, and the private extension repos are exactly where a
deploy key or an API token gets committed once and then lives forever in a blob
that no `git rm` removes. Two things worth doing once, outside this pipeline:
run a history-wide secret scan over each private repo (`gitleaks detect`,
`trufflehog git`, or GitHub's own secret scanning where the plan includes it),
and switch on **GitHub Push Protection** organization-wide so the next one is
refused at push time rather than found later. A rotated credential is cheap; a
credential nobody knows is in the history is not.

## Tests and coverage

- Every extension with PHP source needs `tests/phpunit`. A config-only
  extension may opt out in `.ckconform` — `tests=optional -- <reason>` — and
  the reason is not optional.
- `phpunit.xml.dist` must declare a `<coverage>` section scoped to real
  extension code (exclude the civix shim and DAO/BAO boilerplate). Without it
  `--coverage-text` measures nothing while still looking like a passing gate.
- **Runtime deprecations are test failures.** CiviCRM announces them at
  runtime via `CRM_Core_Error::deprecatedWarning()` /
  `deprecatedFunctionWarning()`, which end in
  `trigger_error(..., E_USER_DEPRECATED)`. Two ingredients turn that into a red
  build, and both live in the template: `convertDeprecationsToExceptions="true"`
  in `phpunit.xml.dist`, and `error_reporting(E_ALL)` in
  `tests/phpunit/bootstrap.php` — PHPUnit's error handler ignores anything
  outside the mask, and the PHP CLI default hides `E_DEPRECATED`, which
  `Civi\Test\CiviTestListener` only widens for its own tests. Without them a
  call into a deprecated code path is green and the notice lands in a PHP log
  nobody reads; `ckconform` (`deprecation-gate`) warns when a repo drops either.
- **A transactional test that lost its transaction is a failure, not a
  surprise.** `Civi\Test\TransactionalInterface` wraps a test in a transaction
  that is rolled back afterwards — but MySQL COMMITs implicitly on DDL, so one
  `CREATE TABLE`, one `CustomField.create`, one schema rebuild in `setUp()` and
  the rollback rolls back nothing. The fixtures leak into every later test and
  the suite stays green until an unrelated test fails somewhere the order
  differs. `ckphpunit` runs the suite with a listener that writes a marker
  inside the transaction and looks for it over a second connection after the
  rollback; a marker that survived can only have been committed. The fix it
  names is the right one: move schema work into `setUpHeadless()`, where the
  `CiviEnvBuilder` runs before the transaction opens.
- CI runs the suite **with** coverage: `ckcoverage` (or at minimum
  `phpunit --coverage-text`). `ckcoverage` runs through `ckphpunit`, so the
  canary above comes with it; nothing in the repo has to reference it.
- `ckcoverage` reports line coverage and fails below the `min_coverage` floor
  in `.ckconform`. Adopt it in that order: **measure first, set the floor to
  what you actually have, then ratchet it up.** A floor nobody measured only
  teaches people to ignore a red build — and a floor must never be lowered to
  turn one green.

- **Coverage is the cheap half of the question.** A line can be executed by a
  test that asserts nothing about it. `ckmutate` (infection) rewrites the code
  under the suite — flips a comparison, drops a return — and reports the
  mutants no test killed; each survivor is a covered line the suite does not
  actually check. It reads `mutation_min_msi` from `.ckconform` (optionally
  `mutation_min_covered_msi`) and is a **no-op that exits 0** without that key,
  so adopt it the same way as coverage: measure, set the floor to what you
  have, ratchet.

  **Recommendation: switch it on in the scheduled `compat.yml` caller
  (`mutation: true`), never in the push run**, and scope it with
  `mutation_paths` to the domain logic and the exporters — the code where a
  surviving mutant means something. Without `mutation_paths` it mutates only
  the lines changed against `CK_MUTATE_BASE` (default `origin/main`), which is
  the useful mode on a branch but not the one a weekly reading wants. A
  mutation run costs a suite run per mutant batch and its score moves with
  refactors that changed no behaviour; as a push gate that is a slow, flappy
  build.

Licence declarations (`info.xml`, `composer.json`, every `package.json`) must
agree with each other. *Which* licence is your policy, not this standard's, so
pin the expected values in an optional `.ckconform` in the extension root and
`ckconform` will enforce them — that file lives in your repo, so a private
policy never has to be published here:

```
license=Proprietary          # info.xml <license> + composer.json
npm_license=UNLICENSED       # every tracked package.json
copyright=Example Ltd        # must appear in LICENSE.txt
template_custom=phpcs.xml.dist -- <reason>   # deliberate template deviation (ckinit --check/--update)
```

SPDX disjunctive licensing (`"license": ["MIT", "GPL-2.0"]`) is allowed in both
manifests, and satisfies the policy when the expected licence is one of the
members — permitted, but not unchecked: an unread array would be a hole straight
through every licence rule.

The `<url desc="Licensing">` civix scaffolds points at the AGPL text. Relicensing
usually edits `<license>` and leaves the link — and a reader trusts the link over
the tag, so `ckconform` fails when the two disagree. A closed-source package also
wants `"private": true` in `package.json`: `UNLICENSED` states intent, `private`
is what makes `npm publish` refuse.

The tooling section is machine-checked by `ckconform` (run from the extension
root) — CI should run it alongside cklint.

## Taint analysis: `cktaint`

`cktaint` runs Psalm — and Psalm *only* as a taint engine, never as a second
phpstan — over the extension, asking one question: **does request input reach a
dangerous sink without being escaped on the way?** It ships in the image and
needs no per-repo config. Since the fleet-wide clean run the gate **blocks** on
the classes where a true positive is an outright vulnerability — `TaintedSql`,
`TaintedShell`, `TaintedInclude`, `TaintedUnserialize`, `TaintedSSRF`. The
noisier classes (file paths, headers, cookies, callables, eval, LDAP, secrets)
are `errorLevel="info"` in the bundled config: printed in the report, never
part of the exit code. On an image from before cktaint existed, the CI step
skips with a log line instead of failing on "command not found".

```
cktaint                 # whole extension
cktaint CRM/Foo.php     # one file or directory
cktaint --baseline      # accept today's findings, see only new ones
```

### What it finds

Psalm cannot see CiviCRM core, so CiviKitchen supplies a deliberately small set
of stubs (`/opt/civikitchen-psalm/stubs`, signatures verified against core):

| role | modelled |
| --- | --- |
| sources | **PSR-7 requests** — everything readable off a route handler's `$request`: `getQueryParams` / `getParsedBody` / `getCookieParams` / `getServerParams` / `getUploadedFiles` / `getAttribute(s)` / `getHeader(s)` / `getHeaderLine` / `getRequestTarget`, the `UriInterface` getters (`getQuery`, `getPath`, `getHost`, …), the body via `StreamInterface::getContents` / `__toString` / `read`, and an upload's `getClientFilename` / `getClientMediaType`. Plus `CRM_Utils_Request::retrieve` / `retrieveValue` / `exportValues` / `retrieveComponent` (Psalm covers `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE` natively) |
| SQL sinks | `CRM_Core_DAO::executeQuery` / `executeUnbufferedQuery` / `singleValueQuery` / `composeQuery` (the `$query` argument only), `CRM_Utils_File::runSqlQuery`, and every raw fragment of `CRM_Utils_SQL_Select` (`from` / `join` / `select` / `where` / `groupBy` / `having` / `orderBy` / `onDuplicate`, the `insertInto`/`replaceInto`/`syncInto` table) |
| path sinks | `CRM_Utils_File::createDir` / `cleanDir` / `copyDir` / `duplicate` / `sourceSQLFile` / `findFiles` / `getFilesByExtension` / `replaceDir` / `createFakeFile` / `resizeImage`, `UploadedFileInterface::moveTo` |
| header sinks | `CRM_Utils_System::redirect` / `setHttpHeader` / `download` (its `$name`/`$mimeType`/`$ext` become Content-Disposition and Content-Type) |
| SSRF sinks | `CRM_Utils_HttpClient::get` / `post` / `fetch` and Guzzle — `Client::request` / `requestAsync` / `get` / `post` / `put` / `patch` / `delete` / `head`, the URI argument only |
| escapes | `CRM_Utils_Type::escape` / `escapeAll`, `CRM_Core_DAO::escapeString` / `escapeStrings` — for **SQL taint only**, so a value escaped for the database is still tainted at a shell or path sink. `CRM_Utils_File::makeFileName` / `makeFilenameWithUnicode` for **paths**, `CRM_Utils_String::purifyHTML` for HTML, and `CRM_Utils_String::munge` for all of them at once (it reduces the value to `[a-zA-Z0-9]` plus a separator) |

Everything PHP itself offers is Psalm's own: `exec`/`system`/`shell_exec`/
`proc_open`, `include`, `eval`, `unserialize`, `header`/`setcookie`,
`file_get_contents`/`fopen`, `curl_setopt`, and `escapeshellarg`/
`htmlspecialchars` as escapes. The stubs above are only what Psalm cannot see:
CiviCRM core, and the PSR-7/Guzzle classes that live in the `vendor/` cktaint
ignores.

Modelling decisions worth knowing, because they decide what you see:

- The `$params` array of `executeQuery` is **not** a sink. That is the safe
  path (`composeQuery` type-validates and escapes every placeholder), and
  tainting it would flag exactly the code this standard asks you to write.
- `CRM_Utils_Type::validate()` is **not** an escape. For `String`/`Text`/
  `Memo`/`Link` it returns the value unchanged, so taint flows straight
  through it — a stub that stayed silent here would launder every injection
  that goes through a validated string.
- The same for `$args` of `CRM_Utils_SQL_Select::where()` and friends, and for
  `param()`: the interpolation array is the safe half of the builder, only the
  expression string is a sink.
- `$request->getMethod()` is **not** a source. It is compared, never
  concatenated, and tainting it would flag every `if ($request->getMethod()
  !== 'POST')` guard the standard asks you to write.
- `CRM_Utils_String::stripPathChars()` is **not** an escape. It strips quotes
  and shell metacharacters but leaves `/`, `.` and `..` alone, so a traversal
  walks straight through it — it stays a pass-through in the graph.
- Guzzle's `$options` (body, headers, query) is not an SSRF sink; only the
  URI is. An attacker-controlled body sent to a fixed host is not SSRF, and
  flagging it would redden every forwarder.

Hooks, APIv4 parameters and Smarty assignments are deliberately *not* modelled
as sources: everything would be tainted, and a report where everything is
flagged is a report nobody reads.

### What it does not see

- Anything that leaves PHP and comes back: a value stored via a hook, passed
  through the API kernel, or rendered by a Smarty template. Psalm follows
  direct data flow, not the framework.
- HTML/XSS. `TaintedHtml` is switched off: CiviCRM output escaping happens in
  templates Psalm never analyses, so what remains would be Psalm's blind spot,
  not missing escaping.
- Values that leave a function through a by-reference out-parameter.
  `parse_str($request->getUri()->getQuery(), $query)` is the one that matters
  in practice: `$query` comes out clean as far as Psalm is concerned, so a
  handler that parses its query string this way is invisible. `json_decode()`
  on the other hand propagates fine — read a webhook body, index into the
  decoded array, and the flow is still followed.
- Additional call sites on a sink it already reported. Psalm reports **one
  flow per source/sink pair** — fix the first `executeQuery` finding and the
  next one can appear in the following run. A clean `cktaint` after a fix is
  not proof there was only one.

And the other direction: `CRM_Utils_Request::retrieve($name, 'Positive')` *is*
safe (core validates it), but the type arrives as a runtime string, so Psalm
taints it like any other retrieval. Expect false positives of exactly this
shape.

### Handling a finding

1. **Fix the flow** — this is almost always right and almost always small:
   parameterize the query (`%1` + `$params`), run the value through
   `CRM_Utils_Type::escape($value, 'String')`, or validate the path.
2. **Or record it as a justified exception.** Either
   `@psalm-suppress TaintedSql` on the line *with a comment saying why it is
   safe*, or `cktaint --baseline` to write `psalm-baseline.xml`. A baseline
   entry is a dated statement that the finding was read and accepted — a
   baseline nobody wrote a reason for is just a mute button.

Never "fix" a finding by weakening the stubs. They are held in place by
fixture pairs in `tests/images/test-dev-tools.sh` — for every modelled
source/sink combination one file where the flow must be reported and one with
the escape in between that must stay silent — so a weakened stub shows up as a
red image test, not as a quieter report.

### How it became blocking

The gate started as an advisory pilot so the signal could be measured before it
cost anyone a red build. The switch happened after a fleet-wide run came back
clean: the blocking five moved to `errorLevel="error"` in the bundled
`psalm-taint.xml.dist`, everything else to `errorLevel="info"` (visible via
`--show-info`, exit-code-neutral), and the CI step dropped
`continue-on-error`.

A repo that wants its own rules ships a `psalm.xml`/`psalm.xml.dist`; `cktaint`
uses it instead of the bundled config, same as `phpcs.xml` for `cklint`.

## Frontend: JS dependencies, JS tests and browser tests

An extension with a `package.json` has three things the default CI run does
not do: install the tree (nothing is committed — see the lockfile rule above),
run the JS unit suite, and drive the UI in a browser. Five more opt-in inputs
on `extension-ci.yml`, all off by default:

| Input | What it adds |
|---|---|
| `npm_ci` | `npm ci --ignore-scripts` on the runner, before the stack boots. The stack bind-mounts the checkout, so `node_modules/` is there for PHP code that reads an asset bundle. |
| `js_tests` | Runs `npm test`. Implies `npm_ci`. Fails when `package.json` has no `test` script. |
| `bun` | Uses Bun for all of the above instead of npm: `bun install --frozen-lockfile`, `bun run test`, `bun run test:e2e`. Implies the install, the way `js_tests` implies `npm_ci`. Needs a committed `bun.lock`. |
| `playwright` | Own job: boots the stack with port 8080 published and an `admin` / `admin` demo user, then runs `npm run test:e2e` from the runner. Report and traces are uploaded on failure. |
| `node_version` | Node for all of the above. Default `'24'` — the major the dev images ship, so a browser job tests the Node the image actually serves. Still applies under `bun` — see below. |

**Linting the JS needs none of them.** `ckeslint` runs in the `ci` job on every
push, from inside the container, with a toolchain pinned in the image — no Node
setup step, no `npm install`, and no linter devDependency in your
`package.json`. The engine is [oxlint](https://oxc.rs), and the baseline is
deliberately not a style guide: oxlint's `correctness` category (mistakes, not
fashion), Mozilla's `eslint-plugin-no-unsanitized` (`innerHTML` and its family
— an XSS in an extension is an XSS on every site that installs it), and, *only
when the repo has a `tsconfig.json`*, the type-aware TypeScript rules, which is
where `no-floating-promises` and `no-misused-promises` come from. The type-aware
rules apply to `.ts`/`.tsx` only — plain `.js` is checked by the syntactic rules
and `no-unsanitized`. CiviCRM's globals (`CRM`, `cj`, `ts`, `_`, `angular`) are
declared for you; `dist/`, `vendor/`, `node_modules/`, the vendored-asset
directories and `*.min.js` are ignored.

**Node globals in an e2e suite come from the image.** The type-aware rules are
type-aware about `process.env` too: without `@types/node` in the *repo's*
`node_modules` it is an error type, and every expression touching it trips
`no-unsafe-assignment` / `-member-access` / `-return`. `ckeslint` links the
image's pinned copy into `node_modules/@types/node` for the run and removes it
afterwards, so no repo needs the devDependency — but tsgolint does not
auto-include `@types` the way `tsc` does, so your `tsconfig.json` still has to
say so:

```json
{ "compilerOptions": { "types": ["node"] } }
```

A repo that installs its own `@types/node` keeps it; the link is only created
when nothing is there.

Ship your own `.oxlintrc.json` and it wins outright — the baseline is not
merged into it, not layered under it, just not used. The cost of owning it is
owning its `jsPlugins` too: oxlint resolves those against *your* `node_modules`,
so a repo with its own config also turns `npm_ci` on.

An `eslint.config.*` on its own is **not** a config the gate can use: ESLint is
no longer in the image, and `ckeslint` fails with a pointer rather than quietly
linting your code with rules you did not choose. Translate it to an
`.oxlintrc.json`, or drop it and take the baseline.

Two scripts, two suites, and the names are the contract:

- **`test`** — the JS unit suite, in whatever runner the repo picked (vitest,
  bun, `node --test`). The workflow only knows the standard script slot; what
  it starts is your business. For a pure-function suite this is the bulk of
  the frontend's coverage, so it is the one to have first.
- **`test:e2e`** — the Playwright suite. Not `test`, precisely so that
  enabling `js_tests` never accidentally starts a browser run, and so both
  suites stay separately reachable from CI.

`--ignore-scripts` is fixed, not an input. An `install`/`postinstall` hook is
arbitrary code from anywhere in the dependency tree, running against a
writable checkout with a token in the environment, and nothing in an extension
build needs one at that moment. A package that really must build before its
tests should do it from its own `test` script, where it is visible in the log.

`npm_ci` and `js_tests` cost seconds and belong in the push run:

```yaml
    with:
      key: myextension
      js_tests: true      # implies npm_ci
      # bun: true         # same steps, run with Bun instead of npm
```

### Bun instead of npm

`bun: true` switches the package manager for every frontend step at once —
the install, `js_tests`, and the Playwright job. It is deliberately not
per-step: a repo that resolves its dependencies with npm in one job and with
Bun in another is testing two different trees.

- **`bun.lock` must be committed.** The install is
  `bun install --frozen-lockfile`, which refuses without one — the same rule
  the lockfile section already states, and the reason the npm dependency
  cache is switched off on this path (it keys on `package-lock.json`, and
  `setup-node` fails the step when it cannot find one).
- **Node is still installed**, at `node_version`. Bun replaces npm, not the
  runtime: `bun run` starts your scripts, and what those scripts call —
  `tsc`, `vitest`, `playwright` from `node_modules/.bin` — is a
  `#!/usr/bin/env node` shebang. So the two inputs do not compete; there is
  no Bun equivalent of `node_version`, the workflow installs the Bun the
  setup action ships.
- **`--ignore-scripts` has no counterpart.** Bun runs no dependency lifecycle
  script at all unless it is listed as a `trustedDependency`, which is what
  the flag buys on the npm path. It *does* run your own package's install
  scripts, which `npm ci --ignore-scripts` here does not — keep the build in
  the `test` script either way, and the two paths behave the same.
- **`npx playwright install` stays `npx`** in the browser job. That is not a
  package-manager call but the local binary either manager has just placed in
  `node_modules/.bin`.

The browser job does not belong in the push run: it boots a stack, installs
browsers and is the slowest thing in the pipeline. Put it in the scheduled
`compat.yml` caller below, next to the matrix — `playwright: true`.

What that job assumes, because the shared workflow cannot ask:

- the stack is the standalone CI stack from the template
  (`CIVIKITCHEN_SITE_URL=http://localhost:8080`). The port is fixed to 8080 to
  match it: CiviCRM bakes that URL into every asset and redirect, so a site
  published elsewhere serves links to a port that isn't listening. A CMS
  compose file boots fine, but its login is the CMS's, so the suite has to
  handle that itself — the demo user the job creates is a Standalone one.
- the suite reads `CIVICRM_BASE_URL`, `DEMO_USER` and `DEMO_PASS` from the
  environment (the starter's `playwright.config.ts` and `tests/auth.setup.ts`
  already do). The job sets all three; a hardcoded `localhost:8080` works too.
- browsers are installed with `playwright install --with-deps`, all of them,
  so a config with `webkit` or `firefox` projects works without another input.

Copy-pasteable starter, including the config files and the `test:e2e` script:
[`examples/extension-with-playwright/`](../examples/extension-with-playwright/).

The job runs once, on `image` — it is not multiplied by `matrix_images`.
UI behaviour that breaks per CiviCRM version is real but rare, and a browser
run per image is the most expensive thing this pipeline could do by default.
If you need it, say so on the issue rather than working around it in a
repo-local job.

## Private dependencies

civikitchen is public and the shared workflow needs no token to be called —
but not every extension's *dependencies* are public. Two cases, two opt-in
inputs plus one optional secret each. Both default to off; a caller that sets
neither gets exactly the run it has today.

| Input / secret | What it adds |
|---|---|
| `composer_install` | `composer install --no-interaction --no-progress` on the runner, before the stack boots. |
| `composer_deploy_key` (secret) | Private SSH key used for `github.com` during that install, so a private VCS package resolves. |
| `sibling_repo` | `owner/repo` of a second extension: checked out to `.civikitchen-siblings/<repo>` and bind-mounted read-only into the stack, which also enables it. |
| `sibling_deploy_key` (secret) | Private SSH key for that checkout. |

**`composer_install` is not an optimization — for a repo without a committed
`vendor/` it is the precondition for everything else.** The extension's main
`.php` file requires the autoloader at the top, so the entrypoint cannot even
enable the mounted extension: the stack boot fails before a test runs. Commit
`vendor/` or set this input; there is no third option.

It runs on the **runner**, not through the image's own
`CIVIKITCHEN_AUTO_COMPOSER`, and that is the whole point: the deploy key never
enters a container. The container-side auto-composer then sees `vendor/`
through the bind mount and skips the directory, so the two do not collide.
CI installs the locked development tree because static analysis covers tests
and therefore must resolve their framework classes. Release packaging remains
strictly `--no-dev`, so test-only packages never enter the shipped archive.

**`sibling_repo`** is for the extension that implements another extension's
interfaces: the classes must exist at boot (`cv ext:enable` wants the declared
requirement present), and `phpstan` and the test bootstrap resolve them from
the sibling's ext directory. The mount target is the sibling's **extension
key**, read from its `info.xml` — not its repo name, which is free to differ
and is not what CiviCRM registers it under. That is the directory a
`scanDirectories` entry has to point at:

```neon
	scanDirectories:
		- /var/www/html/ext/othersibling/Civi
```

The sibling is mounted **as is**, read-only: no `composer install` runs in it.
A sibling that keeps its own `vendor/` out of git is not supported yet — say
so on the issue rather than working around it.

The checkout lands *inside* your checkout (`actions/checkout` cannot write
outside the workspace) but is not treated as your code: `cklint` ignores
`.civikitchen-siblings/`, and `ckconform` reads tracked files only. Nothing to
add to your `.gitignore` — the directory only ever exists on a runner.

Caller, with both:

```yaml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      key: myextension
      composer_install: true
      sibling_repo: myorg/othersibling
    secrets:
      composer_deploy_key: ${{ secrets.PACKAGE_DEPLOY_KEY }}
      sibling_deploy_key: ${{ secrets.SIBLING_DEPLOY_KEY }}
```

Name the two secrets you pass; do **not** write `secrets: inherit`. Inherit
hands the reusable workflow every secret the repo has, for the sake of two —
and the workflow it hands them to is one you move by retagging. Least
privilege costs one line here.

What goes in those secrets, and why deploy keys rather than a PAT:

- A **read-only deploy key** is scoped to exactly one repository and belongs
  to no person, so it survives someone leaving and cannot reach a second repo
  if it leaks. A PAT is the opposite on both counts. One key per dependency
  repo — that scoping is the entire feature.
- The private half goes into the calling repo's secrets, the public half into
  the dependency repo's *Deploy keys*, with write access **off**.
- The key is configured with `IdentitiesOnly`, so it is the only identity
  offered: a missing grant fails here rather than passing on one runner and
  failing on another because an agent key happened to cover it.

Two limits worth knowing before you switch something else on as well:

- The opt-in extra jobs (`matrix_images`, `upgrade_from_last_release`,
  `schema_parity`, `core_upgrade_from`, `playwright`) boot the stack from a
  plain checkout and
  are **not** wired up for private dependencies. Combining them with these inputs fails fast with
  that message rather than booting an extension that cannot load.
- `composer.lock` still has to be committed — see the lockfile rule above. An
  install that is not reproducible is not a dependency, it is a moving target.
  The install runs on the **runner's** PHP, not the image's, and installs the
  lock as it stands (no `update`, no `--ignore-platform-reqs`). Pin
  `config.platform.php` in `composer.json` to the PHP the extension really
  runs on, so resolution and execution agree.

## Compatibility: test the range you claim

`info.xml` `<compatibility><ver>` is a promise; the default CI run checks one
CiviCRM version, on one day, installed from scratch. Five opt-in inputs on the
shared `extension-ci.yml` close that gap. All five default to off — a caller
that sets none of them gets exactly the run it has today.

| Input | What it adds |
|---|---|
| `matrix_images` | One extra job per CiviKitchen image tag (comma-separated): boots the stack and runs the suite against that CiviCRM. |
| `lifecycle` | After the suite, in the running stack: `disable` → `enable` → `uninstall` → `install`, then asserts the extension is installed again — plus the post-uninstall and log checks described [below](#the-lifecycle-gate-what-cklifecycle-adds-to-the-cycle). |
| `upgrade_from_last_release` | Installs the newest reachable git tag, swaps the working tree to the tested commit, runs `cv upgrade:db --mode=ext` and asserts no upgrade stayed pending. |
| `schema_parity` | Own job: installs the last release and upgrades it to this commit, then installs this commit from scratch, and diffs the two schemas over the extension's own tables. See [below](#schema-parity-does-the-upgrader-arrive-where-install-does). |
| `core_upgrade_from` | Own job: installs the site on an older CiviKitchen image, then moves that same database to the run's image and upgrades it with `cv upgrade:db`. See [below](#core-upgrade-what-an-existing-site-goes-through). |

Pick the ends of your claimed range, not everything in between: the oldest
minor you support and current stable. The image tags are `:standalone-<minor>`
(e.g. `:standalone-6.12`) and the moving `:standalone`; see
[images.md](images.md#tags--versions). A `<minor>` tag keeps being rebuilt
(newest patch, current `ck*` tooling) only while it is on the supported list —
`CK_STANDALONE_EXTRA_MINORS` in `toolbelt/versions.env`. If your matrix pins a
minor, make sure it is listed there; a one-off rebuild of anything else is
*Build Dev Images* → `workflow_dispatch` → `extra_standalone_minors`.

The matrix jobs run the **suite**, not the full `ci` pass: `cklint`,
`ckconform`, `phpstan` and the coverage floor are enforced by the tools inside
the image, so re-running them on a pinned older image grades that image's
tooling rather than your extension. What the extra boot answers is the version
question — does it install here, do the tests pass here.

### The lifecycle gate: what `cklifecycle` adds to the cycle

The step runs `cklifecycle` from the image, and the `cv ext:*` sequence is only
its setup. Two things around it are the actual gate.

**After uninstall, the database must be back where it started.** `cv
ext:uninstall` exiting 0 means `uninstall()` did not throw — nothing more. The
check asserts that no table the extension declares (`sql/*.sql`, `xml/schema/`)
or that carries its `<file>` prefix survived, that `civicrm_managed` holds no
row for the module, and that no option group, option value or scheduled job
with that prefix is left running against code that is gone.

**Nothing may fail quietly during the cycle.** Every step's output plus the
delta of the CiviCRM ConfigAndLog files is scanned for `SQLSTATE[…]`, `DB
Error`, `Table '…' doesn't exist`, `Unknown table`, `Unknown column`,
`Duplicate column`, `Syntax error or access violation` and PHP
Fatal/Parse/Warning/Recoverable lines. An `install()` whose `try`/`catch`
swallows a missing-table error otherwise leaves the run green and the site
broken.

`PHP Notice` and `PHP Deprecated` are **not** matched: core emits them on every
supported version, and deprecations are already covered by the phpunit config's
`convertDeprecationsToExceptions` and by phpstan. The gate is scoped to this
job and never to the suite — a negative test that asserts an API call fails is
supposed to log an error.

The findings land in the job summary. A line that is genuinely expected is
declared in `.ckconform`, with a reason, the same shape `ckinit` uses:

```ini
lifecycle_log_ignore=Unknown column 'is_legacy' -- dropped in the 5.x upgrader
```

### Schema parity: does the upgrader arrive where install() does?

`upgrade_from_last_release` proves the upgrader **runs** and leaves nothing
pending. It says nothing about where it **arrives** — and `install()` and the
`upgrade_NNNN()` chain are two independent descriptions of the same schema with
nothing keeping them in step. `NOT NULL` in one and nullable in the other,
`varchar(64)` here and `varchar(255)` there, an index the installer creates and
the upgrader forgets: every fresh install is fine, every upgraded site is
quietly different, and no later upgrader ever reconciles them, because none of
them knows there is anything to reconcile.

```yaml
      schema_parity: true
```

Two databases in one stack: install the last tag, move the mount to this
commit, `cv upgrade:db --mode=ext`, dump — then `down -v`, boot again on this
commit for a fresh install, dump, and diff. The diff is scoped to the tables the
extension declares itself (`schema/*.entityType.php`, `xml/schema/**`), because
core's own tables are not your business and would drown the signal.

`mysqldump --no-data` plus a normalised text diff, not Atlas: the CI stack
publishes no database port, so any tool has to run inside the app container —
and that container already ships a mysql client, while Atlas would mean a pinned
binary download per run to diff two whole schemas it has no way to restrict to
your tables. The normalisation is deliberately small and documented in
`toolbelt/bin/ckschemadiff` (the `AUTO_INCREMENT` counter and mysqldump's version
wrappers, and nothing else). The trade is that this compares DDL *text*, so a
semantically empty difference is possible; the fix when it happens is one more
normalisation rule there.

The job skips itself, with a log line, when the extension declares no tables of
its own or nothing has been released yet — unlike `upgrade_from_last_release`,
where a missing tag means you switched on a check you cannot satisfy, both of
these are ordinary states for a perfectly healthy extension. Two stack boots on
one runner, so: scheduled caller, not the push run.

### Core upgrade: what an existing site goes through

`matrix_images` moves the core but installs from scratch every time;
`upgrade_from_last_release` moves the extension across a core that stays put.
The combination neither of them reaches is the one every real site is in:
records and configuration created on core N, and then the core underneath them
moves to N+1. That is where a managed entity reconciles differently after a
schema change, where a saved search's stored API params stop being valid, where
a custom field's column survives a migration in name only.

```yaml
      core_upgrade_from: ghcr.io/jfilter/civikitchen:standalone-6.12
```

One image tag, and it is the version to upgrade **from** — the oldest minor you
claim. The **target** is the run's own `image` (or the compose file's default),
which reads the way you think about it: *upgrade from the oldest minor I claim
to the one I test on*. So the two must not be the same version; the job says so
and fails rather than passing green over an upgrade that never happened.

What the job does, in one stack:

1. boots the stack on `core_upgrade_from` — which installs CiviCRM and enables
   your extension, on the old version;
2. runs `tests/upgrade/seed.php` (optional, see below);
3. recreates the containers on the target image, keeping the database **and**
   the site directory;
4. runs `cv upgrade:db`, then asserts the database reached the code's version,
   that the version actually moved, that no extension upgrade stayed pending,
   and that your extension is still installed;
5. runs `tests/upgrade/assert.php` (optional).

**The fixtures are yours, and both are optional.** Which records prove
persistence is the extension's business, so the workflow defines two slots and
no schema — the same deal as the `test:e2e` script name:

- **`tests/upgrade/seed.php`** — run with `cv scr` on the old core, right after
  the install. Create the records and configuration whose survival matters.
- **`tests/upgrade/assert.php`** — run with `cv scr` after the upgrade. Any
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

Scripts rather than a PHPUnit class, deliberately: these assertions have to run
against the **live site database**, and the headless harness points
`CIVICRM_DSN` at the isolated `civicrm_test` scratch DB. A phpunit-based
fixture would pass while testing a database the upgrade never touched.

Without the two files the job still boots the old core, upgrades it and runs
the four asserts above — worth having, and the honest limit is that it says
nothing about your own data. The log says as much when it finds no fixtures.

Two things to know before you rely on it:

- It is the **slowest single check** in the pipeline: two boots plus a full core
  schema upgrade. Scheduled caller only.
- A `:standalone-<minor>` tag off the supported list freezes with the tooling
  it was last built with, so a from→to pair is only meaningful while both tags
  exist — the same caveat the matrix carries. And like the browser job, this one runs once, on `image`;
  it is not multiplied by `matrix_images`. A multi-hop upgrade matrix is a
  separate feature, not a flag.

Each entry costs a full stack boot, so **do not put the matrix in the push
run**. Keep `ci.yml` fast (it is what a PR waits on and what automerge gates
on) and add a second, thin caller for the slow checks — one file, one schedule:

```yaml
# .github/workflows/compat.yml
name: Compatibility
on:
  schedule:
    - cron: '0 5 * * 1'   # Mondays, after the weekly image rebuild
  workflow_dispatch:      # and on demand, before a release

permissions:
  contents: read

jobs:
  compat:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      key: myextension
      # This caller gets the default `ci` job too — there is one reusable
      # workflow, not two. Point it at the moving edge instead of repeating
      # the pinned :v1 run every push already does: together with the oldest
      # minor below, the weekly run then covers both ends of the range.
      image: ghcr.io/jfilter/civikitchen:standalone
      # Oldest minor from info.xml <compatibility>.
      matrix_images: ghcr.io/jfilter/civikitchen:standalone-6.12
      lifecycle: true
      upgrade_from_last_release: true
      # The other half of that question: the upgrader ran, but did it arrive
      # where install() does? Skips itself when the extension owns no tables.
      schema_parity: true
      # The site an existing user has: installed on that oldest minor, then
      # upgraded to the image above. Two boots and a core schema upgrade —
      # the slowest check here, and the one nothing else covers.
      core_upgrade_from: ghcr.io/jfilter/civikitchen:standalone-6.12
      # Browser tests, for a repo that has a test:e2e script — another stack
      # boot, which is exactly why it lives here and not in ci.yml.
      # playwright: true
      # Mutation testing (ckmutate): does the suite assert on what it covers.
      # A no-op until .ckconform sets mutation_min_msi — and set
      # mutation_paths there too, or a weekly run mutates the whole tree.
      mutation: true
      # The drift job already runs on every push in ci.yml.
      check_template: false
```

A scheduled workflow only runs from the default branch, so a red weekly run
means the released state is broken — worth an issue, not a rerun.
`workflow_dispatch` is there for the moment that matters most: before cutting a
release.

`compat.yml` is **not** template-managed: which versions you claim is your
policy, so `ckinit` neither stamps nor checks this file.

## Workflow

`cklint` → `phpstan` → `CIVICRM_UF=UnitTests phpunit` locally and in CI;
`ckmodernize` for mechanical migrations. See
[extension-development.md](extension-development.md).
