# ckcommon.sh — the plumbing every ck* tool needs, defined once.
#
# Sourced from ../lib relative to the tool's real path: the image carries the
# toolbelt at /opt/civikitchen/toolbelt and only links the tools into PATH.
#
# Shell plumbing only. Anything with a structure is parsed elsewhere —
# `civikitchen.yaml` by `ckconform --policy-env`, XML and JSON by php.
#
# shellcheck shell=bash
# shellcheck disable=SC2034  # everything defined here is used by the sourcing tool, not here.

ck_tool="${ck_tool:-$(basename "$0")}"

# The toolbelt root, where every toolchain and config the tools use lives.
ck_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Exit 2 is "cannot run", distinct from 1 = "ran, found something". CI treats
# those differently; override with ck_exit.
ck_die() {
    echo "${ck_tool}: $*" >&2
    exit "${ck_exit:-2}"
}

ck_require_extension_root() {
    [ -f info.xml ] || ck_die "no info.xml here — run from the extension root."
}

# safe.directory per call: in CI the checkout is owned by the runner's uid while
# the tool runs as www-data, and git calls that mismatch dubious ownership —
# `ls-files` then silently reports NOTHING, which every caller here would
# misread as "this repo has no files of that kind".
#
# The value is the WORKTREE ROOT, not the cwd: an extension subdirectory of a
# multi-extension repo is not the directory git refuses. Walked rather than
# asked, because `rev-parse --show-toplevel` is itself refused under dubious
# ownership; `.git` is a file in a linked worktree and a directory otherwise.
ck_git_root() {
    local dir="$PWD"
    while [ -n "$dir" ]; do
        if [ -e "$dir/.git" ]; then printf '%s' "$dir"; return 0; fi
        [ "$dir" != / ] || break
        dir=$(dirname "$dir")
    done
    printf '%s' "$PWD"
}

ck_git() { git -c safe.directory="$(ck_git_root)" "$@"; }

ck_in_git_repo() { ck_git rev-parse --is-inside-work-tree >/dev/null 2>&1; }

# --- civikitchen.yaml --------------------------------------------------------------
# Read through ckconform, which owns the format; never parsed here. Why that
# matters: toolbelt/ckconform/src/Policy.php.

# Sets CK_POLICY_<KEY> for every scalar key, ` -- <reason>` stripped. A missing
# file sets nothing, so callers test for an empty value.
ck_policy_load() {
    local env
    env=$("$ck_root/bin/ckconform" --policy-env) || ck_die "could not read civikitchen.yaml"
    eval "$env"
}

# What a release archive leaves out, `dir <name>` / `file <name>` per line: the
# central list ± this repo's dist_exclude/dist_include, already resolved. Read
# through ckconform for the reason civikitchen.yaml is — one owner, in the language
# that owns the list (toolbelt/ckconform/src/DistPaths.php). Non-zero when a
# declared value is not a repo-relative path.
ck_dist_paths() { "$ck_root/bin/ckconform" --dist-paths; }

# --- info.xml and composer.json ----------------------------------------------
# XML is parsed as XML and JSON as JSON: a line-oriented `tr | grep -o | sed`
# reads a <license> spanning two lines as empty and is blind to attributes.

# $1 = file, $2 = 'key' (the root attribute) or a child element name.
ck_xml_field() {
    "$ck_root/bin/ck" internal xml-field "$1" "$2"
}

# $1 = file, $2 = top-level key. A missing file or key prints nothing.
ck_json_field() {
    "$ck_root/bin/ck" internal json-field "$1" "$2"
}

