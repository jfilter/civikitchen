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
```
