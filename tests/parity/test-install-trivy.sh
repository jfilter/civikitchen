#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"

cat > "$work/bin/uname" <<'SH'
#!/usr/bin/env bash
case "$1" in
  -s) echo "${FAKE_UNAME_SYSTEM:-Linux}" ;;
  -m) echo "$FAKE_UNAME_MACHINE" ;;
  *) exit 2 ;;
esac
SH

cat > "$work/bin/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) output="$2"; shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
printf '%s\n' "$url" > "$FAKE_CURL_LOG"
: > "$output"
SH

cat > "$work/bin/sha256sum" <<'SH'
#!/usr/bin/env bash
/bin/cat > "$FAKE_SHA_LOG"
SH

cat > "$work/bin/tar" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
destination=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -C) destination="$2"; shift 2 ;;
    *) shift ;;
  esac
done
cat > "$destination/trivy" <<'TRIVY'
#!/usr/bin/env bash
echo 'Version: 0.72.0'
TRIVY
chmod +x "$destination/trivy"
SH

chmod +x "$work/bin/"*

run_case() {
  local machine="$1"
  local asset="$2"
  local sha="$3"
  local runner="$work/runner-$machine"

  mkdir -p "$runner"
  FAKE_UNAME_MACHINE="$machine" \
  FAKE_CURL_LOG="$runner/curl.log" \
  FAKE_SHA_LOG="$runner/sha.log" \
  RUNNER_TEMP="$runner" \
  PATH="$work/bin:$PATH" \
    "$root/.github/scripts/install-trivy.sh" > "$runner/out"

  grep -q "trivy_0.72.0_${asset}.tar.gz$" "$runner/curl.log"
  grep -q "^${sha}  ${runner}/trivy_0.72.0_${asset}.tar.gz$" "$runner/sha.log"
  grep -q '^Version: 0.72.0$' "$runner/out"
}

run_case x86_64 Linux-64bit bbb64b9695866ce4a7a8f5c9592002c5961cab378577fa3f8a040df362b9b2ea
run_case aarch64 Linux-ARM64 2ca2c023109c2db6b2b77366b6717291452d4531167377d95c79547f0c8e3467

if FAKE_UNAME_SYSTEM=Darwin FAKE_UNAME_MACHINE=arm64 \
    RUNNER_TEMP="$work/unsupported" PATH="$work/bin:$PATH" \
    "$root/.github/scripts/install-trivy.sh" > "$work/unsupported.out" 2>&1; then
  echo "unsupported platform was accepted" >&2
  exit 1
fi
grep -q 'no pinned Trivy build for Darwin/arm64' "$work/unsupported.out"

echo "trivy installer pins: ok"
