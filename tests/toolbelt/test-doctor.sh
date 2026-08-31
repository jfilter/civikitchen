#!/usr/bin/env bash
# doctor's whole job is to be right about a host it does not run on, so every
# verdict is exercised against a synthesised one: PATH is replaced by a
# directory of stubs, which makes "absent" mean absent rather than "absent on
# the machine that happened to run the suite".
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# Absolute, so the stub PATH cannot decide which bash interprets the script.
real_bash="$(command -v bash)"

# The utilities doctor itself uses. Resolved now, while PATH is still real.
real_awk="$(command -v awk)"
real_grep="$(command -v grep)"
real_head="$(command -v head)"
real_uname="$(command -v uname)"

# A host is a directory of stubs: $1 names it, then name=banner pairs.
host() {
  local name="$1" dir pair tool banner
  shift
  dir="$work/$name"
  mkdir -p "$dir"
  for pair in awk="$real_awk" grep="$real_grep" head="$real_head" uname="$real_uname"; do
    printf '#!/bin/sh\nexec %s "$@"\n' "${pair#*=}" > "$dir/${pair%%=*}"
    chmod +x "$dir/${pair%%=*}"
  done
  for pair in "$@"; do
    tool="${pair%%=*}"
    banner="${pair#*=}"
    printf '#!/bin/sh\necho "%s"\n' "$banner" > "$dir/$tool"
    chmod +x "$dir/$tool"
  done
  echo "$dir"
}

# Everything a complete host has; a case drops or overrides what it is about.
COMPLETE=(
  'make=GNU Make 4.4.1'
  'bash=GNU bash, version 5.2.15(1)-release'
  'php=PHP 8.3.10 (cli)'
  'phpdbg=PHP 8.3.10 (phpdbg)'
  'git=git version 2.45.0'
  'curl=curl 8.7.1'
  'composer=Composer version 2.7.6'
  'uvx=uvx 0.5.11'
  'docker=Docker version 27.1.1'
  'npm=10.8.1'
)

# Sets `out` and `status`. Not via command substitution: that runs in a
# subshell, where the exit code assignment would be discarded.
out=''
status=0
run_doctor() {
  local dir="$1"
  set +e
  PATH="$dir" "$real_bash" "$root/scripts/doctor.sh" > "$work/out" 2>&1
  status=$?
  set -e
  out="$(cat "$work/out")"
}

expect() { # label, needle, haystack
  case "$3" in
    *"$2"*) ;;
    *) fail "$1: expected '$2' in output:\n$3" ;;
  esac
}

refute() { # label, needle, haystack
  case "$3" in
    *"$2"*) fail "$1: did not expect '$2' in output:\n$3" ;;
  esac
}

# --- a complete host is silent about problems --------------------------------
run_doctor "$(host complete "${COMPLETE[@]}")"
[ "$status" -eq 0 ] || fail "complete host: expected exit 0, got $status"
refute 'complete host' 'MISSING' "$out"

# --- no Python runner at all -------------------------------------------------
without_uvx=()
for entry in "${COMPLETE[@]}"; do
  [ "${entry%%=*}" = uvx ] || without_uvx+=("$entry")
done
run_doctor "$(host no-pyrun "${without_uvx[@]}")"
[ "$status" -eq 1 ] || fail "no python runner: expected exit 1, got $status"
expect 'no python runner' 'uvx|pipx' "$out"

# --- pipx alone satisfies it: the Makefile pins versions for both ------------
run_doctor "$(host pipx-only "${without_uvx[@]}" 'pipx=pipx 1.6.0')"
[ "$status" -eq 0 ] || fail "pipx-only host: expected exit 0, got $status"
expect 'pipx-only host' 'ok       pipx' "$out"

# --- Apple's make with a modern gmake beside it is usable, not broken --------
run_doctor "$(host apple-make "${COMPLETE[@]}" 'make=GNU Make 3.81' 'gmake=GNU Make 4.4.1')"
[ "$status" -eq 0 ] || fail "apple make + gmake: expected exit 0, got $status"
expect 'apple make + gmake' 'WARN     make' "$out"
expect 'apple make + gmake' 'gmake is 4.4.1' "$out"

# --- Apple's make alone is a hard stop ---------------------------------------
run_doctor "$(host apple-make-only "${COMPLETE[@]}" 'make=GNU Make 3.81')"
[ "$status" -eq 1 ] || fail "apple make alone: expected exit 1, got $status"
expect 'apple make alone' 'MISSING  make' "$out"
expect 'apple make alone' '3.81 is too old' "$out"

# --- a missing interpreter is a hard stop ------------------------------------
without_php=()
for entry in "${COMPLETE[@]}"; do
  [ "${entry%%=*}" = php ] || without_php+=("$entry")
done
run_doctor "$(host no-php "${without_php[@]}")"
[ "$status" -eq 1 ] || fail "no php: expected exit 1, got $status"
expect 'no php' 'MISSING  php' "$out"

# --- line coverage is a hard prerequisite of the fast PHP suite -------------
without_coverage=()
for entry in "${COMPLETE[@]}"; do
  [ "${entry%%=*}" = phpdbg ] || without_coverage+=("$entry")
done
run_doctor "$(host no-coverage "${without_coverage[@]}")"
[ "$status" -eq 1 ] || fail "no coverage driver: expected exit 1, got $status"
expect 'no coverage driver' 'MISSING  coverage' "$out"

# --- Docker is the slow loop only: absent must not fail the fast one ---------
without_docker=()
for entry in "${COMPLETE[@]}"; do
  [ "${entry%%=*}" = docker ] || without_docker+=("$entry")
done
run_doctor "$(host no-docker "${without_docker[@]}")"
[ "$status" -eq 0 ] || fail "no docker: expected exit 0, got $status"
expect 'no docker' 'WARN     docker' "$out"

echo "doctor suite OK"
