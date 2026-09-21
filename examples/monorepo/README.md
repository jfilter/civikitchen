# Two extensions in one repository

The tree the monorepo self-test workflow runs `extension-ci.yml` against:
`ckmonoaddon` `<requires>` `ckmonobase`, both in this repository, so the
workflow has to handle a `working_directory` other than `.`, a second
`/civikitchen-repo` mount for the git-reading tools, and a sibling mounted out
of the same checkout.

- `.docker/docker-compose.ci.yml` carries two managed mounts: the extension at
  `/var/www/html/ext/<key>`, where CiviCRM has it enabled, and the checkout at
  `/civikitchen-repo`. The addon adds the dependency's directory by hand,
  outside the managed block, targeting the dependency's extension KEY
  (`/var/www/html/ext/de.civico.ckmonobase`) — that is where a `<requires>`
  entry is looked up.
- Both directories are stamped by `ckinit --update`. The repository root is
  four levels up from `.docker/` here, two for an extension directly below it;
  `ckinit` computes the depth.
- This tree sits three directories below the repository root, so `ckconform`
  does not read it as a several-extensions layout (that is direct
  subdirectories only) and judges the workflow file as a whole. The
  several-extensions checks are covered by `ckconform`'s own fixtures; this
  tree exercises the workflow.
- Neither example carries a civix scaffold: no `<civix><format>`, no
  `*.civix.php`, and the classloader for `Civi\` is declared in `info.xml`.
  The addon's class uses the base's, which only resolves once the base is
  installed in the same site.
- The root-only files (`renovate.json`, `.github/workflows/ci.yml`) belong to
  the repository, so neither extension directory carries one. The caller here
  is `.github/workflows/monorepo-self-test.yml`.
