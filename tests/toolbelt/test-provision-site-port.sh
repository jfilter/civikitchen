#!/usr/bin/env bash
# Apache also serves a localhost site URL's port, so a browser sharing the app's
# network opens the URL CiviCRM generates. Port 80, other hosts and https add nothing.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export CK_APACHE_CONF="$work/site-port.conf"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

CIVIKITCHEN_SITE_URL="http://localhost:8095" ck_listen_on_site_port
grep -qx 'Listen 8095' "$CK_APACHE_CONF" || fail "a localhost:8095 site URL must add Listen 8095"
grep -qx '<VirtualHost \*:8095>' "$CK_APACHE_CONF" || fail "the extra port must get a vhost on the docroot"
grep -qx '    DocumentRoot /var/www/html' "$CK_APACHE_CONF" || fail "the extra vhost must serve /var/www/html"

for url in "http://localhost" "http://localhost:80" "http://civi.example.org:8095" "https://localhost:8443" ""; do
  CIVIKITCHEN_SITE_URL="$url" ck_listen_on_site_port
  [[ ! -e "$CK_APACHE_CONF" ]] || fail "site URL '$url' must not add a port (and must drop a stale one)"
done

echo "site port: ok"
