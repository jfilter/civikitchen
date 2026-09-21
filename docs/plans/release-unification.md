# Plan: one release path for every extension repo

Status: steps 1–3 and "Also in this round" implemented, not yet released;
steps 4–5 open. Targeted at v1.26.0.

## Where things stand

An extension repo that follows the civikitchen template can reach a tagged
release in three different ways today:

1. **The reusable release workflow.** A caller `release.yml` invokes
   `extension-release.yml@v1` on a tag push: `ckrelease check`, `ckrelease
   dist`, install smoke test, GitHub release. The caller is deliberately *not*
   a template-managed file (`docs/extension-releases.md`, "Adopting it in a
   repo"), so adoption is per repo and `release-workflow` only warns.
2. **A deploy tool's release command.** It bumps `<version>` and
   `<releaseDate>` in `info.xml`, commits `Release X.Y.Z` and tags `vX.Y.Z`
   locally. Repos that deploy to an environment which demands a release tag
   use it, whether or not they call the release workflow.
3. **By hand, or not at all.** Tags pushed without a release commit, or no
   tags for any version.

The consequences observed while cutting v1.23.0:

- Versions were bumped past without a tag. `release-tags` turned that into
  failures across the fleet; the gaps were closed by tagging each version at
  the last commit that carried it.
- Version strings drifted outside SemVer (`X.Y.Z.W`). Neither the caller
  trigger nor a plain-tag deploy gate accepts them, so such a repo cannot
  release at all.
- Pre-releases (`X.Y.Z-alpha3`) never triggered the documented caller, because
  GitHub matches tag filters against the whole ref name. Fixed in v1.23.0 for
  the workflow and the documented trigger; existing callers still carry the old
  pattern.
- The two cut paths spell the release commit differently (`Release X.Y.Z`
  versus the `release X.Y.Z` in the docs).
- A commit that predates the move from `.ckconform` to `civikitchen.yaml`
  cannot be released through `@v1`: `ckconform --policy` refuses the old file.
  Tagging such a commit records history; it does not publish anything.
- GitHub runs the release workflow from the tagged commit. A historic tag on a
  commit without a caller, or whose trigger does not match the tag, publishes
  nothing; a historic tag on a commit whose trigger matches publishes that old
  code. Historic tags go on the last commit that carried the version *and* does
  not trigger.
- Cutting a release on top of a version that was never tagged opens a new
  `release-tags` gap at once. The previous version needs its historic tag in
  the same pass.
- The smoke test installs a private `<requires>` only from a pinned release of
  that dependency (`policy.extension_sources` with `release:`), so a dependency
  that has never released blocks every dependent release. Registry lookups for
  a private key fail with "Unrecognized extension".
- Before v1.23.0, a late release of an older version took Latest from the newest
  one.

## Target

- Every extension repo calls `extension-release.yml`, or declares
  `release=none -- <reason>` in `civikitchen.yaml`. Nothing in between.
- The caller `.github/workflows/release.yml` is a template-managed file,
  including the trigger that matches plain and pre-release tags. Drift is
  caught by the existing template drift job.
- `release-workflow` fails instead of warning.
- `info.xml` `<version>` is `X.Y.Z` or `X.Y.Z-<pre-release>`; anything else
  fails a conformance check, because no release path can publish it.
- One spelling for the release commit, `Release X.Y.Z`, in
  `docs/extension-releases.md` and in every tool that writes one.

## Steps

1. **Template.** Add `release.yml` to `MANAGED_FILES` in `scaffold/ckinit.php`
   with the pre-release-aware trigger; seed it for new repos. Fixture in
   `tests/ckinit/`.
2. **Checks.** `release-workflow`: warn → fail, `release=none` with a reason
   stays the only opt-out. New `version-format` check on `info.xml`. Fixtures
   for both, including a four-component version and a pre-release.
3. **Docs.** `docs/extension-releases.md`: adoption becomes `ckinit --update`;
   the "Cutting one" example commits `Release X.Y.Z`. `docs/releases.md`
   changelog entries marked **Breaking** where a consumer has to act.
4. **Consumer pass, before the tag.** Per repo: `ckinit --update`, fix a
   non-SemVer version with a real release, or declare `release=none` with the
   reason. Order the pass by `<requires>`: a private dependency releases before
   the repos that pin it, and each dependent gets its `extension_sources` pin
   and the App secrets in the caller. Run `ckconform` from the main branch of
   this repo against every consumer and record the counters before and after.
5. **Release.** Changelog section, image build and promote, tag `v1.26.0`.

## Also in this round

- `permission-closure` reads `'permission' => …` specs, `check()` literals,
  menu XML and `.aff.json`, but not the plural `'permissions'` an APIv4 entity
  or action declares. A typo there passes silently today. Extend the check with
  fixtures, then compare `permission-closure` output across every consumer
  before and after: new warnings are expected and belong in the consumer pass.

## Decided

- Repos that have never cut a release get `release=none` with the reason
  `TODO: not released yet; switch to the managed release caller with the first
  release`, the same text in every repo so the remaining ones are found by
  searching for it. The target stays one release path for all.
- A repo whose `info.xml` is below an existing tag releases a version above
  that tag; tags are not deleted.

- `require_changelog` stays opt-in; the managed caller does not set it.
- The smoke test stays on by default; a repo whose install cannot be reached
  headless sets `smoke_test: false` with a reason comment. Both inputs live
  below the managed block of the caller, so `ckinit --update` keeps them; no
  policy key is involved.
