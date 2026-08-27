#!/usr/bin/env bash
# CIVIKITCHEN_LOCALES fetches the version-matching core l10n tarball and
# unpacks only the requested locales into [civicrm.l10n]; the default locale
# has to be one of them. Fake curl serves a tarball built here; fake cv
# answers the version and path queries and records the rest.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/tar/civicrm/l10n/de_DE/LC_MESSAGES" "$work/tar/civicrm/l10n/fr_FR/LC_MESSAGES" "$work/tar/civicrm/sql"
fail() { echo "FAIL: $*" >&2; exit 1; }

echo de > "$work/tar/civicrm/l10n/de_DE/LC_MESSAGES/civicrm.mo"
echo fr > "$work/tar/civicrm/l10n/fr_FR/LC_MESSAGES/civicrm.mo"
echo sql > "$work/tar/civicrm/sql/civicrm_data.de_DE.mysql"
tar -C "$work/tar" -czf "$work/civicrm-6.17.2-l10n.tar.gz" civicrm

cat > "$work/bin/curl" <<'FAKE'
#!/usr/bin/env bash
# fake curl: the last argument is the URL; serve the file of that name.
printf '%s\n' "${*: -1}" >> "$CURL_LOG"
file="$CURL_DIR/$(basename "${*: -1}")"
[[ -f "$file" ]] || exit 22
cat "$file"
FAKE
cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CV_LOG"
case "$1" in
  ev) echo 6.17.2 ;;
  path) echo "$CV_L10N_DIR" ;;
esac
FAKE
# chown to the web user is not possible on the host; a no-op stands in.
printf '#!/usr/bin/env bash\nexit 0\n' > "$work/bin/chown"
chmod +x "$work/bin/curl" "$work/bin/cv" "$work/bin/chown"
export PATH="$work/bin:$PATH"
export CURL_LOG="$work/curl.log" CURL_DIR="$work" CV_LOG="$work/cv.log" CV_L10N_DIR="$work/site/l10n"

ck_as_web() { "$@"; }
export CK_L10N_BASE_URL="https://l10n.example.org"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

# Unset: nothing fetched.
: > "$CURL_LOG"
CIVIKITCHEN_LOCALES="" ck_locales
[[ ! -s "$CURL_LOG" ]] || fail "no locales requested, nothing should be fetched"

# One locale: the version-matching tarball, only that locale, lcMessages set.
: > "$CV_LOG"
CIVIKITCHEN_LOCALES="de_DE" CIVIKITCHEN_DEFAULT_LOCALE="de_DE" ck_locales >/dev/null
grep -qx 'https://l10n.example.org/civicrm-6.17.2-l10n.tar.gz' "$CURL_LOG" || fail "expected the 6.17.2 tarball, got: $(cat "$CURL_LOG")"
[[ "$(cat "$work/site/l10n/de_DE/LC_MESSAGES/civicrm.mo")" == de ]] || fail "de_DE .mo not installed"
[[ ! -e "$work/site/l10n/fr_FR" ]] || fail "fr_FR was not requested"
grep -q 'setting:set lcMessages=de_DE' "$CV_LOG" || fail "lcMessages not set"

# Two locales, no default: both land, lcMessages untouched.
: > "$CV_LOG"; /bin/rm -rf "$work/site"
CIVIKITCHEN_LOCALES="de_DE, fr_FR" ck_locales >/dev/null
[[ -f "$work/site/l10n/fr_FR/LC_MESSAGES/civicrm.mo" && -f "$work/site/l10n/de_DE/LC_MESSAGES/civicrm.mo" ]] || fail "both locales expected"
! grep -q 'setting:set' "$CV_LOG" || fail "no default locale, lcMessages must stay"

# A locale the tarball does not carry fails instead of leaving English behind.
if CIVIKITCHEN_LOCALES="xx_XX" ck_locales >/dev/null 2>&1; then fail "unknown locale must fail"; fi
# A default outside the list, or without a list, fails before any download.
if CIVIKITCHEN_LOCALES="de_DE" CIVIKITCHEN_DEFAULT_LOCALE="fr_FR" ck_locales >/dev/null 2>&1; then fail "default outside the list must fail"; fi
if CIVIKITCHEN_LOCALES="" CIVIKITCHEN_DEFAULT_LOCALE="de_DE" ck_locales >/dev/null 2>&1; then fail "default without locales must fail"; fi
if CIVIKITCHEN_LOCALES="de-DE" ck_locales >/dev/null 2>&1; then fail "malformed locale must fail"; fi

echo "provision locales: ok"
