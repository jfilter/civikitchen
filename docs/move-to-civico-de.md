# Moving civikitchen to civico-de

The repository moves from `jfilter/civikitchen` to `civico-de/civikitchen`,
the images from `ghcr.io/jfilter/civikitchen` to
`ghcr.io/civico-de/civikitchen`, and the docs get a site built from `docs/`.
The old images stay pullable for repos that have not moved yet.

## What a transfer does

| Thing | After the transfer |
|-------|--------------------|
| Web and git URLs | Redirect to the new location, until someone creates a repository at the old one. A repository with more than 100 Actions uses in the week before is retired by GitHub, so nobody else can take the name. |
| `uses: jfilter/civikitchen/…@v1` | Fails. GitHub Actions does not follow redirects for actions or reusable workflows, so every caller is broken from the transfer until it names `civico-de`. |
| Container and npm packages | Stay with the account that published them; the link to the repository is removed. `ghcr.io/jfilter/civikitchen` and `@jfilter/civicrm-profile-schema` stay under jfilter, unchanged. |
| New image builds | Push to `ghcr.io/civico-de/civikitchen` on their own: `IMAGE_PREFIX` is built from `github.repository_owner`. |
| Secrets and variables | Move with the repository. |
| Release App | Owned by jfilter and installed on this repository only, so the `git-tag` job cannot mint a token after the move. |
| GitHub Pages | Not redirected, which is why the docs site is deployed after the move. |

## Decisions

- **The move ships as v1.40.0, marked Breaking.** The `@v1` line continues
  under the new owner; there is no second line to maintain.
- **The jfilter images are frozen, not dual-published.** The last build before
  the move keeps serving `:v1` and the moving tags under jfilter, without daily
  security rebuilds. `cleanup-ghcr.yml` prunes only the owner's packages and
  leaves them alone. Deleting them is a separate decision once no repo pulls
  them.
- **No alias for the old names.** Template, tools, fixtures and docs name
  `civico-de` only. `ckconform`'s `moved-repository` warns on every remaining
  old name in a consumer's non-prose files, which makes the consumer pass
  checkable.
- **The docs site is built with Zensical** from `zensical.toml`, pinned in the
  Makefile.

## Inventory

In this repository, everything `git grep -nE 'jfilter/civikitchen|@jfilter'`
finds, except the dated CHANGELOG entries, whose links keep working through
the redirect, and an author e-mail address. Beyond the rename, the GHCR API
calls in `release-verify-images.sh` and `prune-ghcr-candidates.sh` move from
`/users/{owner}/packages` to `/orgs/{org}/packages`, and the `ref-pin`
exemption in `zizmor.yml` follows the new owner.

Outside it, the consumers are a search, not a list:
`rg -l 'jfilter/civikitchen|@jfilter/civicrm-profile-schema'` over every
checkout that calls the workflows, pulls the images, depends on the schema
package or tracks them in dependency-update config.

## Sequence

1. **Prepare civico-de.** Allow public packages in the organisation's package
   settings. Transfer the release App to the organisation and install it on
   the repository after step 2; GitHub does not document whether its client
   id and key survive, so compare them and replace `RELEASE_APP_CLIENT_ID` and
   `RELEASE_APP_PRIVATE_KEY` if not.
2. **Transfer the repository.** Then `git remote set-url origin` in every
   local checkout and worktree, `gh repo set-default civico-de/civikitchen`,
   and enable Pages with the workflow source, so the push of step 3 deploys
   the docs site.
3. **Push the rename**, with `gmake test` and `gmake lint` green beforehand.
   Its *Build Dev Images* run creates `ghcr.io/civico-de/civikitchen`, private
   like every new organisation package: set it public as soon as it appears.
   The run's self-test pulls without credentials and fails if it gets there
   first; only the full run of step 4 counts for the release.
4. **Build the images**: `gh workflow run build-dev-images.yml` for the full
   run a release needs, then check an anonymous pull of
   `ghcr.io/civico-de/civikitchen:standalone`. Publish the schema package
   under the new scope with `gh workflow run publish-schema.yml` and give it
   the visibility and repository access the jfilter one has.
5. **Release v1.40.0** as [releases](releases.md) describes: move the
   `[Unreleased]` entries under `[1.40.0]`, commit and push, then
   `scripts/release.sh 1.40.0 --apply`. The `git-tag` job proves the App works
   under the new owner.
6. **Consumer pass**, per repo: `ckinit --update` rewrites the managed files
   (callers, CI compose stack); the seeded `.docker/docker-compose.yml` and
   `civikitchen.yaml` and every non-templated reference are edited by hand;
   the schema package dependency moves to the new scope. A repo is done when
   `ck ci` is green and `moved-repository` is silent. From step 2 until then
   its CI and release workflows do not start, so steps 2 to 6 run back to
   back and no extension is released in between.
