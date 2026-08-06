#!/usr/bin/env bash
# Install trivy $CK_TRIVY_VERSION into $RUNNER_TEMP, verified against
# $CK_TRIVY_SHA256 (the release's own trivy_<version>_checksums.txt entry).
set -euo pipefail

tarball="$RUNNER_TEMP/trivy.tar.gz"
curl -fsSL -o "$tarball" \
  "https://github.com/aquasecurity/trivy/releases/download/v${CK_TRIVY_VERSION}/trivy_${CK_TRIVY_VERSION}_Linux-64bit.tar.gz"
echo "$CK_TRIVY_SHA256  $tarball" | sha256sum -c -
tar -xzf "$tarball" -C "$RUNNER_TEMP" trivy
"$RUNNER_TEMP/trivy" --version
