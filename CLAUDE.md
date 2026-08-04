# civikitchen

CiviCRM Docker images for extension development, plus the `ck*` tool belt baked
into them and the shared CI/release workflows eleven extension repos call.
`README.md` is the user-facing entry point; this file is what an agent needs
that the code does not say out loud.

## Layout

```
docker/     image definitions (standalone, buildkit), entrypoints,
            first-boot runtime, demo profiles
toolbelt/   everything baked INTO an image: bin/ (the ck* CLIs), lib/ (shared
            shell + PHP payloads), ckconform/, phpcs/, phpstan/, psalm/,
            rector/, mago/, oxlint/, oxfmt/
tests/      images/ (boot + dev-tool tests), ckinit/, e2e/ (Playwright)
template/   the extension scaffold ckinit stamps into consuming repos
docs/       the reference material; keep prose there, not in file headers
```

The Docker build context is the **repo root**, so a Dockerfile can COPY from
both `docker/` and `toolbelt/`. Do not move it back under one of them.

## Verify

`make help` lists everything. `make test` and `make lint` need no Docker and
are exactly what `.github/workflows/lint.yml` runs — the workflow is `make`
calls, so CI and a laptop cannot drift.

```bash
make test    # ckconform fixtures, the phpstan extension's rules, ckinit
make lint    # shellcheck, actionlint, zizmor, php -l, profile.json schema
make build   # the standalone image (needs Docker)
```

Always run both before committing. `make test-images` is the ~1 h Docker round;
run it only when the image itself changed.

## Rules that are load-bearing

**One parser per format.** `.ckconform` is parsed only by
`toolbelt/ckconform/src/Policy.php`; shell reads it via `ckconform --policy-env`
or `--policy <key>`. XML and JSON go through `ck_xml_field` / `ck_json_field` in
`toolbelt/lib/ckcommon.sh`. Never add a `sed`/`grep -o` reader for a structured
file — that has produced two real bug classes here, both silent (a coverage
floor that stopped applying, an extension key read from a commented-out
element). A new `.ckconform` key goes into `Policy::KEYS` or `PolicyKeyCheck`
reports it as unknown.

**Select by what a file is, not where it lives.** The lint selectors in the
Makefile use a shebang or a suffix, never a directory list. A directory list
fails open: a file it misses is not reported as unchecked, it is just absent
and the run says "clean". An empty match is an error, not a pass.

**A check needs a fixture that makes it FAIL.** Most ckconform checks print
nothing on success, so a rule that silently stopped matching looks exactly like
a clean repo. Same for a bug fix: fix and fixture in one commit.

**Comments record facts; arguments go in the commit message.** Keep what cannot
be re-derived — which gate owns a question, what broke last time, why a version
has a ceiling. Drop justification and anything `docs/` already says. Never
assert a count in a comment; it goes stale.

**Container paths are the interface.** Moving a repo path is free. Moving a
path inside the image (`/usr/local/bin/ck*`, `/opt/civikitchen-*`) breaks
consuming repos. The image mirrors the repo layout on purpose so one path
expression resolves in both: `toolbelt/bin` + `toolbelt/lib` here,
`/usr/local/bin` + `/usr/local/lib` there.

**One versioned contract.** Workflows, template, `ck*` tools and images are
released together and consuming repos pin `@v1`. A change to any of them is a
change to all — see `docs/releases.md`.

## Traps

- **Reusable workflows.** In `extension-ci.yml` and `extension-release.yml`, a
  `uses: ./…` path resolves against the CALLING repo, which is an extension
  repo. Composite actions and same-repo reusable workflows only work from
  workflows that run here (`build-dev-images.yml`, `release.yml`, `lint.yml`).
  To reach this repo's files from a called workflow, check it out at
  `ref: ${{ job.workflow_sha }}` — never a floating ref.
- **`set -e` and `a && b`.** As a statement in a loop or function, a failing
  AND-list aborts. Use `if`.
- **The `ck*` tools have no `.sh` suffix** — they are commands on PATH. Any
  sweep that globs `*.sh` silently skips the shell that runs in the most places.
- **`shellcheck` needs `external-sources`** (set in `.shellcheckrc`) to follow
  `ckcommon.sh`; without it every helper is reported as an undefined variable.
- **Pins.** `toolbelt/versions.env` holds only pins with more than one reader.
  A single-consumer pin stays where it is used, next to the comment that
  explains its ceiling or floor. Do not collect them.

## Where to look first

- a `ck*` tool misbehaves → `toolbelt/bin/<tool>`, header first
- a conformance rule → `toolbelt/ckconform/src/Check/`, one class per rule
- what a consuming repo must satisfy → `docs/extension-standards.md`
- how an image is built or tested → `docker/*/Dockerfile`, `tests/images/`
