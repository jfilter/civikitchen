# ckconform

Repo-conformance checks for CiviCRM extensions against the civikitchen template.
Complements `cklint` (style) and `phpstan` (types): this one checks the *repo
structure* — the gaps every extension audit turned up.

Run from an extension root. In a civikitchen container `ckconform` is on PATH; the
shim in `images/lib/ckconform` execs `bin/ckconform` here.

## Why this is PHP and not a shell script

It was a shell script. Every bug it ever had was the same bug — parsing
structured formats with line-oriented text tools:

| Symptom | Cause |
|---|---|
| `<license>` over two lines read as empty | `sed` needed both tags on one line |
| `<ext version="3.32">…</ext>` invisible | tag-shaped regex assumed no attributes |
| wrong licence compared | first `"license"` line anywhere in composer.json won |
| `ang/afform/*.aff.html` not seen | fixed-depth glob |
| `@since` check passed on everything | `sed`'s `\+` is unsupported by BSD sed, and fails *silently* |

The last one is the instructive one: a check that cannot fail is worse than no
check, because it reports success. So the rules now parse XML as XML and JSON as
JSON, walk directories recursively, and — the actual point — **have tests**.

## Adding a check

1. `src/Check/YourCheck.php` implementing `Check`: `name()` and
   `run(Context, Reporter)`.
2. Register it in `src/Registry.php`. Order matters — it is the output order.
3. `tests/Check/YourCheckTest.php` extending `CheckTestCase`.

`Context` is the only thing that touches the filesystem: `infoXml()` (SimpleXML),
`json()`, `policy()`/`policyValue()` (the repo's `.ckconform`), `findFiles()`
(recursive), `tracked()`/`isTracked()`/`trackedFiles()` (git), `workflows()`.
If you find yourself reaching for a regex over a structured file, add a method
there instead.

### A check needs a fixture that makes it fail

Not a style preference. Roughly half of these rules print nothing when they pass,
so a rule that silently never fires looks exactly like a repo that is in good
shape. `CheckTestCase::repo([...], git: true)` builds a throwaway extension —
give every check at least one fixture that FAILs and one that passes.

## Running the tests

```bash
docker run --rm -v "$PWD":/work -w /work \
  ghcr.io/jfilter/civikitchen:standalone phpunit --no-coverage
```

No composer, no `vendor/` — `src/Autoloader.php` is a dozen lines and the image
already carries PHP and PHPUnit.

## Policy lives in the consuming repo

Which licence, which coverage floor, whether tests may be skipped — none of that
is this tool's business. It reads an optional `.ckconform` from the extension
root, so a private policy never has to be published here:

```
license=Proprietary          # info.xml <license> + composer.json
npm_license=UNLICENSED       # every tracked package.json
copyright=Example Ltd        # must appear in LICENSE.txt
tests=optional -- <reason>   # the reason is not optional
min_coverage=70              # enforced by ckcoverage
known_hooks=acmeConnectors   # third-party hook names HookDispatchNameCheck should trust
hook_style=listener          # business hooks in scan classes; classic form only for pre-boot/lifecycle/return-value hooks
template_custom=<file>,...  -- <reason>   # read by ckinit --check/--update, not by ckconform
```

`.ckconform` is the one policy file for the whole ck* family — ckconform,
ckcoverage and ckinit all read it, so a repo's deviations from the standard
live in one place with their reasons attached.

## Suppressing a finding

Three escape levels, from narrow to broad — the reason is never optional:

```php
// ckconform-ignore <check-name> -- <reason>        line-scoped: this line + the next
// ckconform-ignore-file <check-name> -- <reason>   the whole file
```

```
ignore_checks=<check-name>,... -- <reason>          .ckconform: skips the checks repo-wide
```

Several checks may be listed comma-separated. A marker without a reason
suppresses nothing and is itself reported, and one naming a check that does
not exist is reported as a dead ignore — a typo'd check name would otherwise
silently narrow nothing.

An inline ignore that silenced nothing in the whole run is reported too
(phpstan's `reportUnmatchedIgnoredErrors`): the finding it covered is gone, so
the ignore now only waits to swallow the next one unnoticed — delete it. Each
listed check name is judged on its own. Not reported: a name whose check
`ignore_checks=` skipped repo-wide, since nothing ever looked for its finding.
