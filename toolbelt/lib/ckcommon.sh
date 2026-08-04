# ckcommon.sh — the plumbing every ck* tool needs, defined once.
#
# SOURCED WITH ONE LINE AND NO FALLBACK BRANCH, because the image mirrors the
# repo layout on purpose:
#
#   checkout   toolbelt/bin/<tool>      + toolbelt/lib/ckcommon.sh
#   image      /usr/local/bin/<tool>    + /usr/local/lib/ckcommon.sh
#
# so `"$(dirname "${BASH_SOURCE[0]}")/../lib/ckcommon.sh"` resolves in both. A
# tool that needs the image path spelled out is a tool that will drift from the
# checkout path — that is why /usr/local/lib was chosen over the /opt paths the
# rest of the toolchain uses.
#
# WHAT BELONGS HERE: shell plumbing — process, path, git and message handling.
#
# WHAT DOES NOT: parsing anything with a structure. `.ckconform` is read by
# `ckconform --policy-env`, XML and JSON by php. Five divergent sed-based
# readers of the same policy file are exactly how this file came to exist; a
# sixth one living here would only make the divergence tidier.
#
# shellcheck shell=bash
# shellcheck disable=SC2034  # everything defined here is used by the sourcing tool, not here.

# The tool's own name, for messages. Every ck* tool prefixes its output with
# it, and every one of them derived it by hand before this.
ck_tool="${ck_tool:-$(basename "$0")}"

# The toolbelt root: /usr/local in the image, <repo>/toolbelt in a checkout.
# Tools consult it only for the "running out of a checkout while developing the
# tools themselves" fallback — in the image every toolchain sits at its own
# /opt path, which the caller tries first.
ck_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Fail with the house message shape: "<tool>: <what went wrong>" on stderr.
# Exit 2 is the toolbelt's "cannot run" code, distinct from 1 = "ran, found
# something" — CI treats those differently and so should a caller.
ck_die() {
    echo "${ck_tool}: $*" >&2
    exit "${ck_exit:-2}"
}

# Eleven tools carried this check as their own copy of the same line.
ck_require_extension_root() {
    [ -f info.xml ] || ck_die "no info.xml here — run from the extension root."
}

# safe.directory per call: in CI the checkout is owned by the runner's uid
# while the tool runs as www-data, and git treats that mismatch as dubious
# ownership — `ls-files` then silently reports NOTHING, which every caller here
# would misread as "this repo has no files of that kind".
ck_git() { git -c safe.directory="$PWD" "$@"; }

ck_in_git_repo() { ck_git rev-parse --is-inside-work-tree >/dev/null 2>&1; }

# --- file-selection patterns -------------------------------------------------
# Kept as patterns rather than as one do-everything list_files(): the callers
# genuinely differ (which ls-files flags, which kinds of generated code count,
# whether paths narrow the list), and a helper that grows a flag per caller is
# a copy with extra steps. What actually drifted between the three copies was
# the PATTERNS, so those are what lives here.
#
# Vendored and sibling trees — was byte-identical in cklint, ckfmt and ckeslint.
ck_re_vendored='(^|/)(node_modules|vendor|dist|build|bower_components|packages|\.civikitchen-siblings)/'
# Shipped build output: never the repo's own source, never worth a finding.
ck_re_minified='\.(min|bundle)\.(js|ts)$'
# civix regenerates these verbatim; formatting or linting them puts every
# `civix upgrade` in a fight with the gate.
ck_re_civix='\.civix\.php$'
ck_re_dao='(^|/)DAO/'
