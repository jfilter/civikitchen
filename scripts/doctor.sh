#!/usr/bin/env bash
# What this checkout needs from the host before `make lint` and `make test` can
# run, reported in one pass.
#
# Without it a missing prerequisite surfaces as `command not found` from inside
# a recipe — after the earlier stages already ran, naming the tool but not what
# wanted it or how to get it. Worse, each run reveals exactly one: the next
# target fails on the next missing tool.
#
# Pinned tools (phpunit, shellcheck, actionlint) are NOT prerequisites; `make
# tools` fetches them into .cache/. Only what has to come from the host is here.
#
# Deliberately standalone and free of the things it checks: the make version
# gate refuses before any recipe runs, so this has to be runnable as
# `bash toolbelt/doctor.sh` with nothing but a shell.
set -euo pipefail

missing=0
warnings=0

case "$(uname -s)" in
  Darwin) platform=mac ;;
  *) platform=deb ;;
esac

say_missing() {
  local tool="$1" why="$2" mac="$3" deb="$4" hint
  if [ "$platform" = mac ]; then hint="$mac"; else hint="$deb"; fi
  printf 'MISSING  %-10s %s\n' "$tool" "$why"
  printf '         %-10s install: %s\n' '' "$hint"
  missing=$((missing + 1))
}

say_warn() {
  printf 'WARN     %-10s %s\n' "$1" "$2"
  warnings=$((warnings + 1))
}

say_ok() {
  printf 'ok       %-10s %s\n' "$1" "$2"
}

# Numeric version compare without sort -V, which BSD sort long lacked.
ver_ge() {
  awk -v have="$1" -v want="$2" '
    BEGIN {
      n = split(have, a, "."); m = split(want, b, ".");
      for (i = 1; i <= (n > m ? n : m); i++) {
        x = (i <= n ? a[i] + 0 : 0); y = (i <= m ? b[i] + 0 : 0);
        if (x > y) exit 0;
        if (x < y) exit 1;
      }
      exit 0;
    }'
}

# First numeric dotted token of a --version banner.
ver_of() {
  "$@" 2>/dev/null | head -1 | grep -oE '[0-9]+(\.[0-9]+)+' | head -1
}

check_version() {
  local tool="$1" floor="$2" why="$3" mac="$4" deb="$5" found
  if ! command -v "$tool" >/dev/null 2>&1; then
    say_missing "$tool" "$why" "$mac" "$deb"
    return
  fi
  found="$(ver_of "$tool" --version || true)"
  if [ -z "$found" ]; then
    say_ok "$tool" "present (version not detected)"
    return
  fi
  if ver_ge "$found" "$floor"; then
    say_ok "$tool" "$found"
    return
  fi
  say_missing "$tool" "$found is too old, needs >= $floor — $why" "$mac" "$deb"
}

check_present() {
  local tool="$1" why="$2" mac="$3" deb="$4"
  if command -v "$tool" >/dev/null 2>&1; then
    say_ok "$tool" "present"
  else
    say_missing "$tool" "$why" "$mac" "$deb"
  fi
}

echo "civikitchen doctor — host prerequisites for make lint / make test"
echo

# Apple's make 3.81 ignores .SHELLFLAGS, so recipes would run without
# `set -eu -o pipefail` and a failing stage in a pipe would report success.
# The Makefile refuses to parse below 3.82. A macOS host commonly has a modern
# one installed as `gmake` while `make` stays Apple's — that is a usable setup
# and gets told how to use it, not told to install what it already has.
check_make() {
  local found gfound
  found="$(ver_of make --version || true)"
  if [ -n "$found" ] && ver_ge "$found" 3.82; then
    say_ok make "$found"
    return
  fi
  gfound="$(ver_of gmake --version || true)"
  if [ -n "$gfound" ] && ver_ge "$gfound" 3.82; then
    say_warn make "PATH make is ${found:-absent}, but gmake is $gfound — run gmake here, or put \$(brew --prefix make)/libexec/gnubin first in PATH"
    return
  fi
  say_missing make \
    "${found:-absent} is too old, needs >= 3.82 — below it .SHELLFLAGS is ignored (fail-open recipes)" \
    'brew install make' \
    'apt install make'
}
check_make

# The Makefile sets SHELL := bash from PATH precisely because macOS freezes
# /bin/bash at 3.2.
check_version bash 4.0 \
  'every recipe; macOS ships 3.2 at /bin/bash' \
  'brew install bash' \
  'apt install bash'

check_version php 8.0 \
  'ckconform, the phpstan rules, the catalog drift gates' \
  'brew install php' \
  'apt install php-cli'

check_present git 'fetching the pinned CiviCRM source tree, and every repo-aware check' \
  'xcode-select --install' 'apt install git'
check_present curl 'downloading the pinned phpunit/shellcheck/actionlint into .cache/' \
  'xcode-select --install' 'apt install curl'
check_present composer 'make test-phpstan (installs the rule package vendor tree)' \
  'brew install composer' 'apt install composer'

# lint-actions runs zizmor and lint-schema runs check-jsonschema. Both are
# Python CLIs invoked through a runner that fetches them on demand — either
# runner does, so requiring one specific binary would be inventing a
# constraint the Makefile does not have.
if command -v uvx >/dev/null 2>&1; then
  say_ok uvx 'present (runs zizmor + check-jsonschema)'
elif command -v pipx >/dev/null 2>&1; then
  say_ok pipx 'present (runs zizmor + check-jsonschema)'
else
  say_missing 'uvx|pipx' 'make lint-actions (zizmor) and lint-schema (check-jsonschema)' \
    'brew install uv   # or: brew install pipx' \
    'apt install pipx  # or: curl -LsSf https://astral.sh/uv/install.sh | sh'
fi

# The slow loop only. `make test` and `make lint` do not touch Docker, so a
# host without it is fine for the fast loop and should not be told otherwise.
if command -v docker >/dev/null 2>&1; then
  say_ok docker 'present'
else
  say_warn docker 'absent — make build, test-images and e2e need it; lint and test do not'
fi

if command -v npm >/dev/null 2>&1; then
  say_ok npm 'present'
else
  say_warn npm 'absent — make e2e and dupcheck need it; lint and test do not'
fi

echo
if [ "$missing" -gt 0 ]; then
  echo "doctor: $missing prerequisite(s) missing, $warnings warning(s)"
  exit 1
fi
echo "doctor: all prerequisites present, $warnings warning(s)"
