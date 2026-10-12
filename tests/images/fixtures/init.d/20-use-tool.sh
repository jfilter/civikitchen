#!/usr/bin/env bash
set -euo pipefail
found="$(ckinittool)"
echo "init.d hook found: ${found}"
