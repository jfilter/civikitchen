#!/bin/bash
# Build-time demo-profile application (buildkit Dockerfile `demo` stage, run as
# root when DEMO_PROFILE is set). Starts the embedded MariaDB on the baked data,
# applies the profile as the buildkit user, then cleanly shuts MariaDB down so
# the baked /var/lib/mysql stays consistent (no first-boot InnoDB recovery).
#
#   apply-profile.sh <profile-dir>     # e.g. /tmp/civikitchen-profiles/eu-ngo
set -euxo pipefail

PROFILE_DIR="${1:?usage: apply-profile.sh <profile-dir>}"

service mariadb start
for i in $(seq 1 60); do mysqladmin -uroot -proot ping >/dev/null 2>&1 && break; sleep 1; done

su -s /bin/bash buildkit -c "bash $(printf '%q' "${PROFILE_DIR}/apply.sh") $(printf '%q' "${PROFILE_DIR}")"

# Clean shutdown — leave the data dir consistent so the runtime boot is fast.
mysqladmin -uroot -proot shutdown
for _ in $(seq 1 30); do [ -S /run/mysqld/mysqld.sock ] || break; sleep 1; done
