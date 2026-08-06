#!/usr/bin/env bash
# Fetch the civicrm-core release $LATEST and regenerate all four catalogs
# against it, in place, so the next step can diff the working tree.
set -euo pipefail

curl -fsSLo /tmp/core.tar.gz \
  "https://github.com/civicrm/civicrm-core/archive/refs/tags/${LATEST}.tar.gz"
mkdir -p /tmp/core
tar -xzf /tmp/core.tar.gz -C /tmp/core --strip-components=1
php toolbelt/ckconform/tools/gen-hook-catalog.php /tmp/core
php toolbelt/phpstan/tools/gen-core-namespace-catalog.php /tmp/core
php toolbelt/phpstan/tools/gen-api4-catalog.php /tmp/core
php toolbelt/phpstan/tools/gen-schema-catalog.php /tmp/core
