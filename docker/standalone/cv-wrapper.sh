#!/bin/bash
# cv wrapper: `docker compose exec app cv ...` runs as root by default, and
# root-run cv leaves root-owned caches, locks and config files behind that the
# www-data web workers can no longer write — a classic source of
# works-in-the-CLI-but-breaks-in-the-browser bugs. Drop to www-data unless
# explicitly opted out via CIVIKITCHEN_CV_AS_ROOT=1.
if [[ "$(id -u)" == "0" && "${CIVIKITCHEN_CV_AS_ROOT:-0}" != "1" ]]; then
    exec runuser -u www-data -- /usr/local/bin/cv.real "$@"
fi
exec /usr/local/bin/cv.real "$@"
