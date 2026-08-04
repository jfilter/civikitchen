# ckcommon.sh — the plumbing every ck* tool needs, defined once.
#
# Sourced with one line and no fallback branch, because the image mirrors the
# repo layout: toolbelt/bin + toolbelt/lib here, /usr/local/bin + /usr/local/lib
# there, so `$(dirname "${BASH_SOURCE[0]}")/../lib/ckcommon.sh` resolves in both.
#
# Shell plumbing only. Anything with a structure is parsed elsewhere —
# `.ckconform` by `ckconform --policy-env`, XML and JSON by php.
#
# shellcheck shell=bash
# shellcheck disable=SC2034  # everything defined here is used by the sourcing tool, not here.

ck_tool="${ck_tool:-$(basename "$0")}"

# The toolbelt root: /usr/local in the image, <repo>/toolbelt in a checkout.
# Only for the "developing the tools themselves" fallback — in the image every
# toolchain sits at its own /opt path, which the caller tries first.
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
ck_git() { git -c safe.directory="$PWD" "$@"; }

ck_in_git_repo() { ck_git rev-parse --is-inside-work-tree >/dev/null 2>&1; }

# --- .ckconform --------------------------------------------------------------
# Read through ckconform, which owns the format; never parsed here. Why that
# matters: toolbelt/ckconform/src/Policy.php.

_ck_conform_bin() {
    local bin
    bin=$(command -v ckconform || true)
    [ -n "$bin" ] || bin="$ck_root/bin/ckconform"
    [ -x "$bin" ] || ck_die "cannot find ckconform, which reads .ckconform"
    printf '%s' "$bin"
}

# Sets CK_POLICY_<KEY> for every scalar key, ` -- <reason>` stripped. A missing
# file sets nothing, so callers test for an empty value.
ck_policy_load() {
    local env
    env=$("$(_ck_conform_bin)" --policy-env) || ck_die "could not read .ckconform"
    eval "$env"
}

# Every value of one repeatable key, verbatim — its callers are the ones whose
# job is to check that the reason is there.
ck_policy_all() { "$(_ck_conform_bin)" --policy "$1"; }

# --- file-selection patterns -------------------------------------------------
# Patterns, not one do-everything list_files(): the callers differ in ls-files
# flags and in which generated code counts. What drifted between the three
# copies was the patterns.
ck_re_vendored='(^|/)(node_modules|vendor|dist|build|bower_components|packages|\.civikitchen-siblings)/'
ck_re_minified='\.(min|bundle)\.(js|ts)$'
# civix regenerates these verbatim; linting them puts every `civix upgrade` in a
# fight with the gate.
ck_re_civix='\.civix\.php$'
ck_re_dao='(^|/)DAO/'
