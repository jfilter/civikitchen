#!/usr/bin/env bash
# Clone every entry of CK_SIBLING_REPO into .civikitchen-siblings/<extension
# key> and write the directory list to $GITHUB_OUTPUT as `paths=`.
#
# The directory is named after the extension KEY, not the repo: that is the
# name CiviCRM registers the extension under, the name `cv ext:enable`
# expects, and the path phpstan's ArchitectureTest probes
# (<ext>/.civikitchen-siblings/<key>). The key is only known once the clone's
# info.xml can be read, so the clone lands in a staging directory named after
# owner AND repo — two siblings may share a basename — and is moved afterwards.
#
# Lives next to action.yml rather than in .github/scripts/: the reusable
# workflows check this repository out with `sparse-checkout: .github/actions`.
set -euo pipefail

# No globbing anywhere below: an entry is a clone target and a path, never a
# pattern, and an unquoted expansion is what splits the list.
set -f

root=.civikitchen-siblings

# The calling workflow checks its own helpers out under the same parent. A
# sibling claiming one of those names would have the workflow delete it.
reserved="ci policy"

# Every entry has to be owner/repo — it becomes a clone target and a path.
# Validated here as well as in the calling job: this is the only place that
# turns the value into a command.
for repo in ${CK_SIBLING_REPO//,/ }; do
  [[ "$repo" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$ ]] \
    || { echo "invalid sibling_repo entry (expected owner/repo): $repo" >&2; exit 1; }
done

# `git -c` keeps the credential in this process: unlike a token in the clone
# URL it never reaches the sibling's .git/config. Never echoed either — base64
# defeats the runner's secret masking.
header="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$CK_SIBLING_TOKEN" | base64 | tr -d '\n')"

mkdir -p "$root"
paths=""
for repo in ${CK_SIBLING_REPO//,/ }; do
  staging="$root/.staging-${repo//\//-}"
  rm -rf "$staging"
  git -c "http.https://github.com/.extraheader=$header" \
    clone --quiet --depth 1 "https://github.com/$repo" "$staging"

  info="$staging/info.xml"
  if [ ! -f "$info" ]; then
    echo "sibling_repo: no info.xml in $repo — that repo is not a CiviCRM extension" >&2
    exit 1
  fi
  # Parsed as XML, not with a tag-shaped regex: an attribute order or a root
  # element split over two lines is legal and would silently yield an empty
  # key. Same reader as the toolbelt's ck_xml_field — inline, because this
  # runs on the bare runner where the toolbelt is not on PATH. php is
  # preinstalled on the GitHub-hosted images.
  # shellcheck disable=SC2016  # the $vars belong to PHP, not the shell.
  key=$(php -r '
    libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_file($argv[1]);
    if ($xml === FALSE) { fwrite(STDERR, "cannot parse {$argv[1]}\n"); exit(2); }
    echo trim((string) $xml["key"]);
  ' "$info")
  if ! [[ "$key" =~ ^[A-Za-z][A-Za-z0-9._-]*$ ]]; then
    echo "sibling_repo: could not read a usable extension key from $repo's info.xml (got: '$key')" >&2
    exit 1
  fi
  case " $reserved " in
    *" $key "*)
      echo "sibling_repo: $repo declares the extension key '$key', which is reserved for the workflow's own checkout under $root/" >&2
      exit 1
      ;;
  esac

  dir="$root/$key"
  if [ -e "$dir" ]; then
    echo "sibling_repo: $repo declares the extension key '$key', but $dir already exists — two entries cannot share a key" >&2
    exit 1
  fi
  mv "$staging" "$dir"

  echo "sibling extension: $key (from $repo)"
  paths="${paths:+$paths }$dir"
done

echo "checked out siblings: $paths"
echo "paths=$paths" >> "$GITHUB_OUTPUT"
