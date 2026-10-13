# Moving civikitchen to civico-de

The repository moves from jfilter/civikitchen to civico-de/civikitchen. The
images move from `ghcr.io/jfilter/civikitchen` to
`ghcr.io/civico-de/civikitchen`, and the docs get a site built from `docs/`.
The old images stay pullable for repos that have not moved yet.

## What a transfer does

| Thing | After the transfer |
|-------|--------------------|
| Web and git URLs | Redirect to the new location until someone creates a repository at the old one. GitHub retires a repository that had more than 100 Actions uses in the week before, so nobody else can take the name. |
| `uses: jfilter/civikitchen/…@v1` | Fails. GitHub Actions does not follow redirects for actions or reusable workflows. Every caller is broken from the transfer until it names civico-de. |
| Container and npm packages | Stay with the account that published them, and lose their link to the repository. `ghcr.io/jfilter/civikitchen` and `@jfilter/civicrm-profile-schema` stay under jfilter, unchanged. |
| New image builds | Push to `ghcr.io/civico-de/civikitchen` without any change, because `IMAGE_PREFIX` is built from `github.repository_owner`. |
| Secrets and variables | Move with the repository. |
| Release App | Owned by jfilter and installed on this repository only, so the `git-tag` job cannot mint a token after the move. |
| GitHub Pages | Not redirected. That is why the docs site is deployed after the move. |

## Decisions

- The move ships as v1.40.0, marked Breaking. The `@v1` line continues under
  the new owner, and there is no second line to maintain.
- The jfilter images are frozen, not published twice. The last build before
  the move keeps serving `:v1` and the moving tags under jfilter, without
  daily security rebuilds. `cleanup-ghcr.yml` prunes only the owner's
  packages and leaves them alone. Deleting them is a separate decision, once no
  repo pulls them.
- There is no alias for the old names. Template, tools, fixtures and docs name
  civico-de only. The `moved-repository` check in ckconform warns on every
  remaining old name in a consumer's non-prose files, so the consumer pass can
  be checked.
- The docs site is built with Zensical from `zensical.toml`, pinned in the
  Makefile.

## Inventory

In this repository, the rename covers everything that
`git grep -nE 'jfilter/civikitchen|@jfilter'` finds. Two things are exempt:
the dated CHANGELOG entries, whose links keep working through the redirect,
and an author e-mail address.

Beyond the rename, two more changes are needed:

- The GHCR API calls in `release-verify-images.sh` and
  `prune-ghcr-candidates.sh` move from `/users/{owner}/packages` to
  `/orgs/{org}/packages`.
- The `ref-pin` exemption in `zizmor.yml` follows the new owner.

Outside this repository, find the consumers by searching rather than keeping a
list. Run `rg -l 'jfilter/civikitchen|@jfilter/civicrm-profile-schema'` over
every checkout that calls the workflows, pulls the images, depends on the
schema package or tracks them in dependency-update config.

## Sequence

1. **Prepare civico-de.** Allow public packages in the organisation's package
   settings. Transfer the release App to the organisation, and install it on
   the repository after step 2. GitHub does not document whether the App's
   client id and key survive the transfer. Compare them, and replace
   `RELEASE_APP_CLIENT_ID` and `RELEASE_APP_PRIVATE_KEY` if they changed.
2. **Transfer the repository.** Then run `git remote set-url origin` in every
   local checkout and worktree, and `gh repo set-default civico-de/civikitchen`.
   Enable Pages with the workflow source, so the push in step 3 deploys the
   docs site.
3. **Push the rename**, with `gmake test` and `gmake lint` green beforehand.
   Its *Build Dev Images* run creates `ghcr.io/civico-de/civikitchen`. Like
   every new organisation package, it starts private, so set it public as soon
   as it appears. The run's self-test pulls without credentials and fails if it
   gets there first. Only the full run in step 4 counts for the release.
4. **Build the images.** Run `gh workflow run build-dev-images.yml` for the
   full run a release needs. Then check that an anonymous pull of
   `ghcr.io/civico-de/civikitchen:standalone` works. Publish the schema
   package under the new scope with `gh workflow run publish-schema.yml`, and
   give it the same visibility and repository access as the jfilter one.
5. **Release v1.40.0** as described in [releases](releases.md). Move the
   `[Unreleased]` entries under `[1.40.0]`, commit and push, then run
   `scripts/release.sh 1.40.0 --apply`. The `git-tag` job proves that the App
   works under the new owner.
6. **Consumer pass**, per repo. `ckinit --update` rewrites the managed files,
   which are the callers and the CI compose stack. Edit by hand the seeded
   `.docker/docker-compose.yml`, the `civikitchen.yaml` and every reference
   outside the template. Move the schema package dependency to the new scope.
   A repo is done when `ck ci` is green and `moved-repository` is silent. From
   step 2 until then, its CI and release workflows do not start. Run steps 2
   to 6 back to back, and release no extension in between.
