# Typing CiviCRM core from outside

CiviCRM core declares few types for what its APIs return. An APIv4 row is a
bare `array`, `Result::first()` is `array|null`, and a database read is
whatever the DAO hands back. Extension code receives `mixed` and narrows it by
hand. This plan adds the missing types for the static analyzer, without
changing core and without code that runs on a site. The types live in this
repository for good; nothing waits on core adopting them.

It targets mago only. phpstan leaves the images
([ADR-0017](adr/0017-php-quality-tools-consolidate-on-mago.md),
[Consolidating on mago](mago-consolidation.md)), and while `ckanalyze` runs as
a shadow gate, a new type changes no consumer's verdict.

## Why

Call sites in 39 extension repositories, outside tests and `vendor/`, counted
with grep on 2026-10-11. `->execute()` and `->first()` also match calls that
are not APIv4.

| Call | Sites | Repos |
|---|---|---|
| `->execute()` | 1896 | 29 |
| `->first()`, `->single()`, `->last()` | 741 | 29 |
| `->column()` | 72 | 15 |
| `civicrm_api4()` | 114 | 17 |
| `Civi::settings()->get()` | 165 | 28 |
| `CRM_Core_DAO::executeQuery()` | 111 | 18 |
| `CRM_Core_DAO::singleValueQuery()` | 53 | 16 |

- About two dozen of these repositories carry their own coercion helpers
  (`int()`, `str()`, `toInt()`, …), mostly to turn such values into scalars.
- In the first shadow run of `mago analyze`, `mixed-assignment` and the other
  `mixed-*` codes made up most findings.
- Core declares `Civi\Api4\Generic\Result` as an `ArrayObject` without type
  arguments, so mago reads every row as `mixed` and rejects index access.

## What goes where

| Kind of type | Home |
|---|---|
| A core declaration that is missing or too wide, such as `@return array` on a method that returns rows | A mago patch file in this repository, a replacement declaration where a patch cannot express the type, or a type provider where a patch would bind the overrides |
| A type that follows from metadata, such as the row shape of an APIv4 `get` from its select list | A type provider in `toolbelt/mago-ext` |
| A value whose type core does not guarantee: a setting written by different code paths, decoded JSON, stored configuration | No static type. The extension narrows it at runtime. |
| An extension's own classes and values | The extension |

A type here states what core returns at runtime, measured on the images'
cores, not what its metadata or SQL type suggests. Where that is not certain,
core's own declaration stays ([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md)).
A wrong type is worse than none: mago then flags healthy code or hides a
defect.

## What mago offers

Checked against mago 1.55.0, the version the images pin:

- **Patches.** `[source] patches` lists PHP files that redeclare a symbol of
  an included or built-in file. mago reads only their types: signatures,
  `@return`, `@throws`, class-level `@template`, `@method` and `@property`.
  A patch refines a symbol; it adds no real members and changes no
  hierarchy. `ckanalyze` writes the configuration of each run, so the patch
  directory is one more line there. Measured on 2026-10-11: a patched
  `@return` on `Result::first()` and on `AbstractAction::execute()`, a
  conditional `@return ($index is null ? … : …)` on the function
  `civicrm_api4()` and a patched class-level `@template` on `DAOGetAction`
  take effect, a patched `@extends \ArrayObject<…>` on `Result` does not. mago ignores `@extends` and `@implements` in patches altogether
  ([mago#2469](https://github.com/carthage-software/mago/issues/2469)).
  mago checks overrides against a patched signature: with `execute()`
  patched, each action whose `execute()` declares a `Result` subclass got
  `incompatible-return-type`.
- **Replacement declarations.** A declaration under `includes`, with core's
  own file excluded, takes the class's place. Measured on 2026-10-11:
  `@extends \ArrayObject<array-key, array<string, mixed>>` there types
  `foreach`, `$result[0]` and `first()` as rows, and the `invalid-array-index`
  finding on `Result` disappears. A replacement has to declare every member of
  the class it replaces.
- **Type providers.** The analyzer SDK registers `MethodReturnTypeProvider`,
  `FunctionReturnTypeProvider` and `PropertyTypeProvider` implementations for
  named targets. `Type` builds literal strings, keyed arrays and generic
  objects (`Type::namedObject($class, ...$typeArguments)`). Measured on
  2026-10-11 with a spike plugin:
  - A target matches by the method's declaring class, and also when that
    class is a subclass of the target: `DAOEntity::get` matches
    `Contact::get()`, the target `Contact::get` does not, and the target
    `AbstractAction::execute` matches an action's own `execute()` too. The
    receiver type names the called class (`Civi\Api4\Contact`).
  - mago keeps the type arguments a provider returns through methods declared
    `@return $this`, such as `addWhere()`, and through a variable assigned
    once.
  - A provider on `execute()` that returns `Result<array{id: int, …}>` types
    `foreach`, `first()`, `single()` and index access with that row, given the
    generic replacement of step 1.
- **The limit.** A provider sees the invocation, its receiver type and its
  arguments, but not the syntax tree. The entity and select list of
  `Contact::get()->addSelect('id')->execute()` therefore travel in the
  receiver's type, one call at a time. A call that is its own statement
  (`$api->addSelect('x');`) returns a type nothing keeps, so the variable's
  type stays as it was.

## Steps

Each step ships fixtures in `toolbelt/mago-ext/tests/cases` that pin the exact
findings, including a healthy example per type that must produce none.

### 1. Declare what core leaves untyped

- Done: `toolbelt/mago/stubs/Civi/Api4/Generic/Result.php` replaces core's
  `Result` for `ckanalyze`, which excludes core's file. It is generic over its
  row type (`@template-covariant TRow = mixed`, `@extends \ArrayObject<array-key, TRow>`):
  `foreach`, index access, `first()`, `last()`, `itemAt()` and `single()`
  return `TRow`; `rekey()`, which renames the keys within each row, returns
  the same class with array rows without a shape. Step 2 fills `TRow` with a row shape.
  The template is covariant: with an invariant one, a `Result` of rows did
  not narrow to a subclass such as `ReplaceResult`, so code after
  `instanceof ReplaceResult` read a `never` (measured on 2026-10-11).
- Rows default to `mixed` because an action may fill its `Result` with
  anything: custom actions write `$result['run_id'] = $id` in
  `_run(Result $result)`. mago types reads and writes of an `ArrayAccess`
  with the same template, so a bound of `array` turned each such write into
  `invalid-array-access-assignment-value` (measured on 2026-10-11). The
  default also lets an extension class extend `Result` without type argument,
  which phpstan requires: it rejects `@extends Result<…>` because core's
  `Result` declares no template.
- Done: the rows are typed where core returns them.
  `Api4ExecuteRowsHook`, a return-type provider in `toolbelt/mago-ext`,
  makes `AbstractAction::execute()` return `Result<array<string, mixed>>`;
  a provider rather than a patch, because it leaves the signature that
  overrides are checked against alone. The rows are keyed by field name.
  Typed `array-key`, a row handed to a parameter declared
  `array<string, mixed>` was a false finding: 24 in 3 of 45 repositories
  with PHP code (measured 2026-10-11). An action that overrides `execute()`
  keeps its own return type: core's `replace`, `autocomplete`,
  `getSearchTasks` and SearchKit's `run`, `download` and `saveFile` return
  subclasses of `Result` with untyped rows (`SearchDisplay.run` with
  `return: 'id'` fills its `Result` with IDs), an extension's action its
  own `Result` subclass.
- Done: a patch in `toolbelt/mago/patches/` types `civicrm_api4()` the same
  way when `$index` is `NULL`. With an index, its `Result` holds one row's
  fields or one column, so it keeps untyped rows. The patch also admits
  `NULL` for `$index`, the default that core's `@param` leaves out. A patch
  cannot tell which action class the names resolve to, so the actions that
  override `execute()` get rows here too: `civicrm_api4('SearchDisplay',
  'run', ['return' => 'id'])` returns IDs that mago reads as arrays.
- Eight actions fill the `Result` that `execute()` returns with something
  other than rows: `PaymentProcessor.refund` with the processor's response
  map, so `$result['refund_status']` is a string, `Contact.getMergedTo` with
  `NULL` for a contact that was never merged, `RecentItem.delete` and
  `Pledge.cancel` with `NULL`, and `Contribution.continueCheckout`, `User.login`,
  `User.passwordReset` and `Totp.confirmSeed` with a status map. mago types
  those values as arrays and flags code that reads them correctly, as core's
  own `first()` and `single()` already do. No extension repository calls any
  of them outside `tests/`, which `ckanalyze` leaves out (grep, 64
  repositories, 2026-10-11). An extension's action that inherits `execute()`
  is typed the same way. Typing only the get, create, save, update and delete
  actions instead left 22 reads of real rows `mixed`, in 12 of 38 extension
  repositories (measured 2026-10-11).
- A `Result` without row type, such as a parameter declared plain `Result`
  or the `ReplaceResult`, `AutocompleteResult` and `SearchDisplayRunResult`
  of the actions that override `execute()`, returns `mixed` from `first()`
  and `single()`, where core declares `array`.
  Code that hands a `Result` on declares its rows:
  `@return Result<array<string, mixed>>`. A default of `array` instead
  turns each scalar write in `_run()` into a false finding. Measured on
  2026-10-11 over 38 extension repositories: the default `mixed` gave 10
  findings on `mixed` rows in 3 of them, a default `array` 4 false findings
  in 3.
- A `Result<array{id: int}>` annotation accepts only a `Result` of that row
  type: passing a parameter so annotated what `execute()` returns is a
  `possibly-invalid-argument` finding, returning it as such a return type an
  `invalid-return-statement`, until step 2 gives `execute()` row shapes
  (measured on 2026-10-11).
- `toolbelt/mago/stub-parity.php` compares each stub with core's file by
  reflection, docblocks aside: `make test` against the pinned core, the image
  tests against each image's core. A member core declares that the stub lacks
  or declares differently fails, because mago would report its callers. A
  member only the stub has is listed: cores before 6.18 lack the error methods
  (`getErrors()`, `addError()`, …), so mago accepts calls to them there: a
  missed finding on those cores, never a false one.
- Once mago honors `@extends` in patches (mago#2469), the replacement becomes
  a patch of the generics and the row-returning methods, and the reflection
  test goes: a patch refines core's own class, so members core adds stay
  visible, and mago's documentation rules out a patch adding a member core
  lacks.
- Done: patches widen two declarations core documents too narrowly.
  `AbstractEntity::getInfo()` returns `array<string, mixed>`: core documents a
  closed key set with `type` as a string, but returns `type` as a list and adds
  keys per entity. `DAOGetAction::addJoin()` takes `$bridge` as
  `string|array|null`: core defaults it to `NULL` and reads an array as the
  first join condition. The standalone boot test checks both on the image.
- `DynamicPropertiesFilterHook` drops mago's `missing-magic-method` on a
  documented `@property` of a class that allows dynamic properties, as every
  DAO does through `DB_DataObject` (mago#2483).
- `Api4ResultFilterHook` drops mago's `invalid-type-cast` on
  `(array) $result`: casting an `ArrayObject` returns its rows, not its
  properties (measured on PHP 8.5, also for a subclass with a public
  property).
- Patches for `CRM_Core_DAO::executeQuery()`, `fetchAll()` and
  `singleValueQuery()`, with the types they return on the images, measured
  first.
- Each patched method gets an image test that calls it on the image's core
  and checks the value against the declared type; the standalone boot test
  does so for `execute()` and `civicrm_api4()`. A core release that changes
  a return type then fails the image build, not a consumer's CI.

### 2. Row shapes for APIv4 `get`

- **Catalog.** The generated APIv4 catalog lists field names only. It gains,
  per field, the data type, whether the value is serialized, whether it can
  be null, and its pseudoconstant suffixes. An extension's own entities come
  from its `schema/`, as their field names do today.
- **Measured value types.** For each data type (Integer, Boolean, Float,
  Money, String, Text, Date, Timestamp, serialized), the PHP type an APIv4
  `get` returns on each image core. The measured table goes into this
  document, and the catalog maps through it, never from the SQL type.
- **Carrying the select.** A patch declares two templates on
  `DAOGetAction`, `TEntity of string` and `TSelect of string`. The provider
  on `DAOEntity::get()` returns `DAOGetAction<'Contact', ''>`, `addSelect()`
  and `setSelect()` return it with the literal names appended, and
  `execute()` returns a `Result` of the row those names describe. A joined
  field (`contact_id.display_name`) resolves through the catalog's joins, a
  `:label` or `:name` suffix to a string. This covers every entity that
  inherits `get()` from `DAOEntity`: 77 of the 95 entity classes in the
  image's core, by reflection on 2026-10-11. Of the rest, 15 declare their
  own `get()`, one inherits `BasicEntity::get()` and two have none; they get
  no shape until a provider covers them.
- **Open rows.** A row shape is open:
  `array{id: int, display_name: string|null, ...<string, mixed>}`. A key
  outside it reads as `mixed`. A builder changed by a statement of its own,
  or by a function it is passed to, can select more than its type shows. A
  closed shape turns reads of those fields into `undefined-string-array-index`
  (measured); an open one stays silent. Misspelled field names remain the
  fluent field rule's job.
- **No receiver assertion.** An assertion provider on `$this` does carry
  `$api->addSelect('x');` into the variable, and a field selected in one
  branch becomes an optional key. It works only while the action class
  declares no templates, because mago then keeps the type arguments without
  checking them. With the templates above, mago reports the same assertion
  as `impossible-type-comparison` and the variable turns `mixed`. The
  precision is not worth the undocumented behaviour: in 58 extension
  repositories (grep, 2026-10-11), `addSelect()` or `setSelect()` is its own
  statement once and part of a chain 1472 times.
- **Accepted gap.** A select narrowed by a later statement of its own
  (`$api->setSelect(['id']);`) leaves keys in the type that the row lacks.
  The same grep found no such call.
- **No shape** when the select list is empty, is not fully literal, contains
  `*`, a function or an alias, or names a custom field or a field the catalog
  does not know. The row then stays `array<string, mixed>` from step 1.
- **Then** `civicrm_api4('X', 'get', ['select' => […]])` through a function
  provider with the same shape code, and `create`, `update` and `save`, whose
  rows hold the written values.

### 3. Settings

`Civi::settings()->get()` keeps `mixed` unless measurement shows otherwise.
For Integer and Boolean settings, compare the PHP type after a write through
the settings form, through `Setting.create` and through
`Civi::settings()->set()`. If the type depends on the writer, a static type
would be a guess.

## Rollout

- Types land while `ckanalyze` is a shadow gate, so no consumer turns red.
  The findings that change are part of the shadow-phase triage: `mixed-*`
  findings disappear, and new ones are mostly guards the types make
  redundant. Step 1, measured on 2026-10-11 over 45 repositories with PHP
  code: 488 findings go, 194 come, 178 of them at 128 places such as
  `is_array($row)`, `(array) $row` or `@var array<string, mixed> $row` on
  rows that are now typed. 14 of the rest read a `Result` that a helper
  returns as plain `Result`.
- A provider that crashes mago fails `ck ci` even as a shadow gate. Providers
  ship only with their fixtures green on the pinned mago.
- When mago becomes the gate (step 3 of the consolidation), the types start to
  count. The changelog of that release names them.

## Risks

- A core release changes what a method returns. The tests of step 1 and the
  measured table of step 2 run against every core the images build, so the
  change shows in the image build.
- Providers depend on mago's young SDK. Every mago bump runs their fixtures.
- An extension that supports an older core than the images do gets the
  images' types. Patches and shapes are tested on supported cores only.

## Not covered

- Psalm's stubs in `toolbelt/psalm/stubs` model taint sources and sinks for
  `cktaint`, not return types. They stay separate.
- APIv3 and the dynamic `$dao->column` properties after a raw query: neither
  has a shape the analyzer can know.
